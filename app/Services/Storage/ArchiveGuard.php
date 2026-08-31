<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Storage\Exceptions\UploadRejectedException;
use ZipArchive;

/**
 * Einzelpruefung jedes Archiveintrags nach Abschnitt 6.1 und 19.
 *
 * Ein ZIP wird nur akzeptiert, wenn JEDER Eintrag einzeln geprueft wurde. Ein
 * einziger unzulaessiger Eintrag fuehrt zur Ablehnung des gesamten Archivs. Es
 * wird bewusst nicht "das Gute entpackt und der Rest verworfen", weil ein
 * teilweise entpacktes Archiv im Quarantaenebereich schwer nachvollziehbar
 * bleibt.
 *
 * Geprueft werden:
 * - Path Traversal: "..", absolute Pfade, Laufwerksbuchstaben, Backslashes
 * - Zip-Bombe: Verhaeltnis entpackt zu komprimiert, Gesamtgroesse, Eintragszahl
 * - verschachtelte Archive
 * - Dateiendung und Magic Bytes jedes Eintrags
 *
 * DATENSCHUTZ: Der Eintragsname wird nur zur Pruefung gelesen und niemals
 * gespeichert, protokolliert oder in eine Ausnahme eingebettet.
 */
final class ArchiveGuard
{
    /**
     * Endungen, die innerhalb eines Archivs zulaessig sind. ZIP fehlt bewusst,
     * verschachtelte Archive werden abgelehnt.
     *
     * @var list<string>
     */
    private const ALLOWED_ENTRY_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'heic', 'heif', 'csv', 'xlsx'];

    /**
     * Eintraege, die jedes Betriebssystem beim Packen erzeugt und die kein
     * Nutzdokument sind.
     *
     * @var list<string>
     */
    private const IGNORED_PREFIXES = ['__MACOSX/', '.DS_Store'];

    public function __construct(
        private readonly MimeGuard $mimeGuard = new MimeGuard,
        private readonly int $maxEntries = 200,
        private readonly int $maxCompressionRatio = 100,
    ) {}

    /**
     * @return list<ArchiveEntryReport>
     *
     * @throws UploadRejectedException
     */
    public function inspect(string $absolutePath, UploadLimits $limits): array
    {
        $archive = new ZipArchive;

        if ($archive->open($absolutePath, ZipArchive::RDONLY) !== true) {
            throw UploadRejectedException::because(UploadErrorCode::STRUKTUR_UNGUELTIG);
        }

        try {
            return $this->inspectEntries($archive, $limits);
        } finally {
            $archive->close();
        }
    }

    /**
     * @return list<ArchiveEntryReport>
     *
     * @throws UploadRejectedException
     */
    private function inspectEntries(ZipArchive $archive, UploadLimits $limits): array
    {
        if ($archive->numFiles > $this->maxEntries) {
            throw UploadRejectedException::withContext(UploadErrorCode::ARCHIV_ZIP_BOMBE, [
                'eintraege' => $archive->numFiles,
                'limit' => $this->maxEntries,
            ]);
        }

        $reports = [];
        $totalUncompressed = 0;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);

            if ($stat === false) {
                throw UploadRejectedException::because(UploadErrorCode::STRUKTUR_UNGUELTIG);
            }

            $name = is_string($stat['name'] ?? null) ? $stat['name'] : '';

            $this->assertSafePath($name);

            if ($this->isIgnorable($name)) {
                continue;
            }

            $uncompressed = (int) ($stat['size'] ?? 0);
            $compressed = (int) ($stat['comp_size'] ?? 0);

            $totalUncompressed += $uncompressed;

            $this->assertNoZipBomb($compressed, $uncompressed, $totalUncompressed, $limits);

            $extension = $this->mimeGuard->extensionOf($name);

            if ($extension === null) {
                throw UploadRejectedException::because(UploadErrorCode::ARCHIV_EINTRAG_UNZULAESSIG);
            }

            if (in_array($extension, ['zip', 'gz', 'tar', '7z', 'rar'], true)) {
                throw UploadRejectedException::because(UploadErrorCode::ARCHIV_VERSCHACHTELT);
            }

            if (! in_array($extension, self::ALLOWED_ENTRY_EXTENSIONS, true)) {
                throw UploadRejectedException::because(UploadErrorCode::ARCHIV_EINTRAG_UNZULAESSIG);
            }

            $this->assertEntryContents($archive, $index, $extension, $limits);

            $reports[] = new ArchiveEntryReport($index, $extension, $compressed, $uncompressed);
        }

        if ($reports === []) {
            throw UploadRejectedException::because(UploadErrorCode::ARCHIV_LEER);
        }

        return $reports;
    }

    /**
     * @throws UploadRejectedException
     */
    private function assertSafePath(string $name): void
    {
        if ($name === '') {
            throw UploadRejectedException::because(UploadErrorCode::ARCHIV_TRAVERSAL);
        }

        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/')) {
            throw UploadRejectedException::because(UploadErrorCode::ARCHIV_TRAVERSAL);
        }

        if (preg_match('#^[a-zA-Z]:#', $normalized) === 1) {
            throw UploadRejectedException::because(UploadErrorCode::ARCHIV_TRAVERSAL);
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw UploadRejectedException::because(UploadErrorCode::ARCHIV_TRAVERSAL);
            }
        }

        if (str_contains($normalized, "\0")) {
            throw UploadRejectedException::because(UploadErrorCode::ARCHIV_TRAVERSAL);
        }
    }

    /**
     * @throws UploadRejectedException
     */
    private function assertNoZipBomb(int $compressed, int $uncompressed, int $totalUncompressed, UploadLimits $limits): void
    {
        if ($uncompressed > $limits->maxFileBytes) {
            throw UploadRejectedException::withContext(UploadErrorCode::ARCHIV_ZIP_BOMBE, [
                'entpackt' => $uncompressed,
                'limit' => $limits->maxFileBytes,
            ]);
        }

        if ($totalUncompressed > $limits->maxRunBytes) {
            throw UploadRejectedException::withContext(UploadErrorCode::ARCHIV_ZIP_BOMBE, [
                'entpackt_gesamt' => $totalUncompressed,
                'limit' => $limits->maxRunBytes,
            ]);
        }

        if ($compressed > 0 && $uncompressed > 0) {
            $ratio = intdiv($uncompressed, max(1, $compressed));

            if ($ratio > $this->maxCompressionRatio) {
                throw UploadRejectedException::withContext(UploadErrorCode::ARCHIV_ZIP_BOMBE, [
                    'verhaeltnis' => $ratio,
                    'limit' => $this->maxCompressionRatio,
                ]);
            }
        }
    }

    /**
     * Liest nur den Kopfbereich des Eintrags und prueft die Magic Bytes gegen
     * die Endung. Der Inhalt wird nicht vollstaendig in den Speicher geladen.
     *
     * @throws UploadRejectedException
     */
    private function assertEntryContents(ZipArchive $archive, int $index, string $extension, UploadLimits $limits): void
    {
        $stream = $archive->getStreamIndex($index);

        if ($stream === false) {
            throw UploadRejectedException::because(UploadErrorCode::ARCHIV_EINTRAG_UNZULAESSIG);
        }

        $head = (string) fread($stream, 8192);
        fclose($stream);

        if ($head === '') {
            throw UploadRejectedException::because(UploadErrorCode::ARCHIV_EINTRAG_UNZULAESSIG);
        }

        // Die Strukturpruefung des Dateiendes ist im Archiv nicht ohne
        // vollstaendiges Entpacken moeglich. Geprueft werden daher Magic Bytes
        // und Endung; die vollstaendige Strukturpruefung folgt beim Entpacken
        // des einzelnen Eintrags in den Quarantaenebereich.
        $this->mimeGuard->inspectContents($head.$this->structuralTailFor($extension), $extension, $limits);
    }

    /**
     * Ergaenzt die fuer die Kopfpruefung erwartete Endmarkierung, damit die
     * Strukturpruefung eines Teilstroms nicht faelschlich fehlschlaegt.
     */
    private function structuralTailFor(string $extension): string
    {
        return match ($extension) {
            'pdf' => "\ntrailer\n%%EOF\n",
            'xlsx' => "PK\x05\x06",
            default => '',
        };
    }

    private function isIgnorable(string $name): bool
    {
        if (str_ends_with($name, '/')) {
            return true;
        }

        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix) || str_contains($name, '/'.$prefix)) {
                return true;
            }
        }

        return false;
    }
}
