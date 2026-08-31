<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Storage\Exceptions\UploadRejectedException;

/**
 * Formatpruefung einer hochgeladenen Datei nach Abschnitt 19 (Uploads).
 *
 * Die Pruefkette laeuft in dieser Reihenfolge, siehe Abschnitt 6.3:
 *
 *   1. Groesse (leer, Limit je Datei)
 *   2. Magic Bytes, also der tatsaechliche Dateityp
 *   3. Abgleich mit der angekuendigten Dateiendung
 *   4. Strukturpruefung: PDF-Header und Trailer, Bild-Header, ZIP-Zentralverzeichnis
 *   5. Ausschluss ausfuehrbarer Inhalte
 *
 * Erst danach folgen Malwarepruefung, Seitenzahl und Fingerabdruck.
 *
 * DATENSCHUTZ:
 * - Es wird nur der Datei-Anfang und das Datei-Ende gelesen, niemals der
 *   gesamte Inhalt in eine Variable, die spaeter protokolliert werden koennte.
 * - EXIF-Daten werden bewusst nicht ausgelesen und nicht weitergegeben
 *   (Abschnitt 6.4).
 * - Der Originaldateiname wird nur als fluechtiges Argument verarbeitet. Er
 *   wird niemals gespeichert; dauerhaft gilt allein die neutrale
 *   Quellenbezeichnung.
 */
final class MimeGuard
{
    /**
     * Der gelesene Kopfbereich muss ZIP-Signatur, HEIC-Brand und PDF-Header
     * sicher abdecken. 8 KB genuegen dafuer deutlich.
     */
    private const HEAD_BYTES = 8192;

    private const TAIL_BYTES = 8192;

    /**
     * Zulaessige Dateiendungen und der dazu erwartete MIME-Typ.
     *
     * @var array<string, list<string>>
     */
    private const EXTENSION_MIME_MAP = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'heic' => ['image/heic', 'image/heif'],
        'heif' => ['image/heic', 'image/heif'],
        'csv' => ['text/csv'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'zip' => ['application/zip'],
    ];

    /**
     * Signaturen ausfuehrbarer oder skriptfaehiger Inhalte. Ein Treffer im
     * Kopfbereich fuehrt zur sofortigen Ablehnung, unabhaengig von der Endung.
     *
     * @var list<string>
     */
    private const EXECUTABLE_SIGNATURES = [
        '<?php',
        '<?=',
        '#!/',
        'MZ',
        "\x7fELF",
        '<script',
        '<%',
    ];

    public function __construct(private readonly PageCounter $pageCounter = new PageCounter) {}

    /**
     * Prueft eine Datei auf der Platte.
     *
     * @param  string|null  $declaredExtension  angekuendigte Dateiendung ohne Punkt.
     *                                          Bewusst nur die Endung, niemals der Originaldateiname.
     *
     * @throws UploadRejectedException
     */
    public function inspectFile(string $absolutePath, ?string $declaredExtension, UploadLimits $limits): FileInspection
    {
        if (! is_file($absolutePath)) {
            throw UploadRejectedException::because(UploadErrorCode::QUELLE_NICHT_VORHANDEN);
        }

        $byteSize = (int) filesize($absolutePath);
        $head = (string) file_get_contents($absolutePath, false, null, 0, self::HEAD_BYTES);
        $tailOffset = max(0, $byteSize - self::TAIL_BYTES);
        $tail = (string) file_get_contents($absolutePath, false, null, $tailOffset, self::TAIL_BYTES);

        $inspection = $this->inspect($head, $tail, $byteSize, $declaredExtension, $limits);

        if ($inspection->category === FileCategory::PDF || $inspection->category === FileCategory::TABELLE) {
            $pages = $this->pageCounter->count($absolutePath, $inspection->category, $inspection->mimeType);

            return new FileInspection(
                $inspection->mimeType,
                $inspection->category,
                $inspection->byteSize,
                $pages,
                $inspection->imageWidth,
                $inspection->imageHeight,
            );
        }

        return $inspection;
    }

    /**
     * Prueft einen vollstaendig im Speicher liegenden Inhalt. Nur fuer kleine
     * Dateien und Archiveintraege vorgesehen.
     *
     * @throws UploadRejectedException
     */
    public function inspectContents(string $contents, ?string $declaredExtension, UploadLimits $limits): FileInspection
    {
        return $this->inspect(
            substr($contents, 0, self::HEAD_BYTES),
            substr($contents, max(0, strlen($contents) - self::TAIL_BYTES)),
            strlen($contents),
            $declaredExtension,
            $limits,
        );
    }

    /**
     * Prueft ausschliesslich die angekuendigte Dateiendung. Wird bereits beim
     * Start des Uploads angewandt, damit ein unzulaessiges Format nicht erst
     * nach der vollstaendigen Uebertragung abgelehnt wird.
     *
     * @throws UploadRejectedException
     */
    public function assertExtensionAllowed(?string $declaredExtension): string
    {
        $extension = $this->normalizeExtension($declaredExtension);

        if ($extension === null || ! array_key_exists($extension, self::EXTENSION_MIME_MAP)) {
            throw UploadRejectedException::because(UploadErrorCode::ERWEITERUNG_UNZULAESSIG);
        }

        return $extension;
    }

    /**
     * Normalisiert einen Dateinamen zu einer reinen Endung in Kleinschreibung.
     * Der Name selbst wird verworfen und nie zurueckgegeben.
     */
    public function extensionOf(string $originalFilename): ?string
    {
        $position = strrpos($originalFilename, '.');

        if ($position === false) {
            return null;
        }

        return $this->normalizeExtension(substr($originalFilename, $position + 1));
    }

    /**
     * Erwarteter MIME-Typ einer zulaessigen Endung, fuer die Anzeige und den
     * Vergleich mit der Providerunterstuetzung.
     *
     * @return list<string>
     */
    public function mimeTypesForExtension(string $extension): array
    {
        return self::EXTENSION_MIME_MAP[$this->normalizeExtension($extension) ?? ''] ?? [];
    }

    /**
     * @throws UploadRejectedException
     */
    private function inspect(
        string $head,
        string $tail,
        int $byteSize,
        ?string $declaredExtension,
        UploadLimits $limits,
    ): FileInspection {
        if ($byteSize <= 0) {
            throw UploadRejectedException::because(UploadErrorCode::DATEI_LEER);
        }

        if ($byteSize > $limits->maxFileBytes) {
            throw UploadRejectedException::withContext(UploadErrorCode::DATEI_ZU_GROSS, [
                'byte_size' => $byteSize,
                'limit' => $limits->maxFileBytes,
            ]);
        }

        $detected = $this->detect($head, $tail);

        if ($detected === null) {
            throw UploadRejectedException::because(UploadErrorCode::MIME_UNBEKANNT);
        }

        $this->assertNotExecutable($head, $detected['category']);

        $extension = $this->normalizeExtension($declaredExtension);

        if ($extension !== null) {
            $allowed = self::EXTENSION_MIME_MAP[$extension] ?? null;

            if ($allowed === null) {
                throw UploadRejectedException::because(UploadErrorCode::ERWEITERUNG_UNZULAESSIG);
            }

            if (! in_array($detected['mime'], $allowed, true)) {
                throw UploadRejectedException::withContext(UploadErrorCode::MIME_TAEUSCHUNG, [
                    'erkannt' => $detected['mime'],
                    'erwartet' => implode(',', $allowed),
                ]);
            }
        }

        $this->assertStructure($head, $tail, $byteSize, $detected['category'], $detected['mime']);

        $dimensions = $this->imageDimensions($head, $detected['category']);

        return new FileInspection(
            $detected['mime'],
            $detected['category'],
            $byteSize,
            $detected['category'] === FileCategory::BILD || $detected['category'] === FileCategory::HEIC ? 1 : null,
            $dimensions[0],
            $dimensions[1],
        );
    }

    /**
     * Erkennung ausschliesslich anhand der Magic Bytes, nicht anhand der
     * Endung und nicht anhand des vom Browser gemeldeten MIME-Typs.
     *
     * @return array{mime: string, category: FileCategory}|null
     */
    private function detect(string $head, string $tail): ?array
    {
        if (str_starts_with($head, '%PDF-')) {
            return ['mime' => 'application/pdf', 'category' => FileCategory::PDF];
        }

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return ['mime' => 'image/jpeg', 'category' => FileCategory::BILD];
        }

        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return ['mime' => 'image/png', 'category' => FileCategory::BILD];
        }

        $heic = $this->detectHeic($head);

        if ($heic !== null) {
            return ['mime' => $heic, 'category' => FileCategory::HEIC];
        }

        if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
            return $this->detectZipFlavour($head, $tail);
        }

        if ($this->looksLikeCsv($head)) {
            return ['mime' => 'text/csv', 'category' => FileCategory::TABELLE];
        }

        return null;
    }

    /**
     * HEIC und HEIF sind ISO-BMFF-Container. Der Brand steht ab Byte 8.
     */
    private function detectHeic(string $head): ?string
    {
        if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') {
            return null;
        }

        $brand = strtolower(substr($head, 8, 4));

        return match ($brand) {
            'heic', 'heix', 'heim', 'heis' => 'image/heic',
            'mif1', 'msf1', 'hevc', 'hevx', 'hevm', 'hevs' => 'image/heif',
            default => null,
        };
    }

    /**
     * XLSX ist ein ZIP-Container mit einer festen Struktur. Die Unterscheidung
     * erfolgt anhand der im Kopfbereich sichtbaren Eintragsnamen und des
     * Zentralverzeichnisses am Dateiende.
     *
     * @return array{mime: string, category: FileCategory}
     */
    private function detectZipFlavour(string $head, string $tail): array
    {
        $searchSpace = $head.$tail;

        $isSpreadsheet = str_contains($searchSpace, 'xl/workbook.xml')
            || str_contains($searchSpace, 'xl/_rels/workbook.xml.rels');

        if ($isSpreadsheet) {
            return [
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'category' => FileCategory::TABELLE,
            ];
        }

        return ['mime' => 'application/zip', 'category' => FileCategory::ARCHIV];
    }

    /**
     * CSV hat keine Magic Bytes. Akzeptiert wird nur reiner Text ohne
     * Nullbytes, mit gueltiger Zeichenkodierung und mindestens einem
     * ueblichen Trennzeichen in der ersten Zeile.
     */
    private function looksLikeCsv(string $head): bool
    {
        if ($head === '' || str_contains($head, "\0")) {
            return false;
        }

        $probe = str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head;

        if (! mb_check_encoding($probe, 'UTF-8') && ! mb_check_encoding($probe, 'ISO-8859-1')) {
            return false;
        }

        $firstLine = strtok($probe, "\r\n");
        $firstLine = $firstLine === false ? $probe : $firstLine;

        foreach ([';', ',', "\t", '|'] as $delimiter) {
            if (str_contains($firstLine, $delimiter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws UploadRejectedException
     */
    private function assertNotExecutable(string $head, FileCategory $category): void
    {
        // In binaeren Containern sind zufaellige Bytefolgen wie "MZ" moeglich.
        // Der Ausschluss greift daher nur am Dateianfang und bei Textformaten.
        $probe = ltrim(substr($head, 0, 256));

        foreach (self::EXECUTABLE_SIGNATURES as $signature) {
            if (str_starts_with($probe, $signature)) {
                throw UploadRejectedException::because(UploadErrorCode::AUSFUEHRBARER_INHALT);
            }
        }

        if ($category === FileCategory::TABELLE && str_contains(strtolower(substr($head, 0, 1024)), '<?php')) {
            throw UploadRejectedException::because(UploadErrorCode::AUSFUEHRBARER_INHALT);
        }
    }

    /**
     * @throws UploadRejectedException
     */
    private function assertStructure(
        string $head,
        string $tail,
        int $byteSize,
        FileCategory $category,
        string $mimeType,
    ): void {
        switch ($category) {
            case FileCategory::PDF:
                // Ein vollstaendiges PDF endet mit %%EOF und traegt einen
                // Querverweis auf das Objektverzeichnis.
                if (! str_contains($tail, '%%EOF')) {
                    throw UploadRejectedException::because(UploadErrorCode::STRUKTUR_UNGUELTIG);
                }

                if (! str_contains($tail, 'startxref') && ! str_contains($tail, 'trailer')) {
                    throw UploadRejectedException::because(UploadErrorCode::STRUKTUR_UNGUELTIG);
                }

                break;

            case FileCategory::BILD:
                if (@getimagesizefromstring($head) === false) {
                    throw UploadRejectedException::because(UploadErrorCode::STRUKTUR_UNGUELTIG);
                }

                break;

            case FileCategory::HEIC:
                // Der Container muss eine plausible Boxlaenge im Kopf tragen.
                $boxLength = unpack('N', substr($head, 0, 4));

                if ($boxLength === false || $boxLength[1] < 8 || $boxLength[1] > $byteSize) {
                    throw UploadRejectedException::because(UploadErrorCode::STRUKTUR_UNGUELTIG);
                }

                break;

            case FileCategory::ARCHIV:
                if (! str_contains($tail, "PK\x05\x06")) {
                    throw UploadRejectedException::because(UploadErrorCode::STRUKTUR_UNGUELTIG);
                }

                break;

            case FileCategory::TABELLE:
                if ($mimeType !== 'text/csv' && ! str_contains($tail, "PK\x05\x06")) {
                    throw UploadRejectedException::because(UploadErrorCode::STRUKTUR_UNGUELTIG);
                }

                break;
        }
    }

    /**
     * @return array{int|null, int|null}
     */
    private function imageDimensions(string $head, FileCategory $category): array
    {
        if ($category !== FileCategory::BILD) {
            return [null, null];
        }

        $size = @getimagesizefromstring($head);

        if ($size === false) {
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    private function normalizeExtension(?string $extension): ?string
    {
        if ($extension === null) {
            return null;
        }

        $normalized = strtolower(trim(ltrim($extension, '.')));

        if ($normalized === '' || preg_match('/^[a-z0-9]{1,8}$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }
}
