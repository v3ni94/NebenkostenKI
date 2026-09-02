<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Storage\Crypto\CipherIntegrityException;
use App\Services\Storage\Crypto\CiphertextHeader;
use App\Services\Storage\Crypto\PlaintextStreamWrapper;
use App\Services\Storage\Crypto\TemporaryUploadCipherFactory;
use App\Services\Storage\Crypto\TemporaryUploadKeyring;
use App\Services\Storage\Exceptions\UploadRejectedException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Einziger zulaessiger Ablageort fuer Originaluploads.
 *
 * VERBINDLICHE DATENSCHUTZREGELN (Abschnitt 3.4, 19 und ADR-007):
 *
 * 1. Es wird ausschliesslich die Disk "temporary_uploads" verwendet. Sie liegt
 *    ausserhalb des Webroots, ist aus jedem Backup ausgeschlossen und wird
 *    kurzfristig geleert. Ein Schreiben auf sftp, s3 oder public ist in dieser
 *    Klasse technisch nicht moeglich.
 * 2. Der Zielpfad ist immer zufaellig. Der Originaldateiname erscheint niemals
 *    auf der Platte, weil Dateinamen personenbezogene Angaben enthalten
 *    koennen und weil ein Verzeichnislisting sonst Inhalte verraten wuerde.
 * 3. Alle Dateien eines Uploads liegen unter einem gemeinsamen zufaelligen
 *    Praefix: Dateiabschnitte, Originaldatei, Seitenbilder, Konvertierungen und
 *    Textauszuege. Die Loeschung entfernt das Praefix vollstaendig, damit keine
 *    Ableitung uebersehen wird.
 * 4. Es gibt keine oeffentliche URL. Eine Auslieferung an den Browser findet
 *    nicht statt, auch nicht als Vorschaubild.
 * 5. JEDE Datei liegt auf der Platte ausschliesslich verschluesselt
 *    (authentifizierte Stromverschluesselung, siehe Namespace Crypto). Jeder
 *    Schreibweg dieser Klasse verschluesselt, jeder Leseweg entschluesselt.
 *    Einen Schalter zum Abschalten gibt es nicht. Die einzige Klartextkopie
 *    entsteht in withDecryptedCopy() fuer ZipArchive und wird unmittelbar
 *    nach Gebrauch ueberschrieben und geloescht.
 */
final class TemporaryUploadStorage
{
    public const DISK = 'temporary_uploads';

    /**
     * Wurzelverzeichnis innerhalb der Disk. Ein sprechender Name erleichtert
     * die Betriebspruefung, ohne Inhalte zu verraten.
     */
    private const ROOT = 'quarantaene';

    /**
     * Unterverzeichnis je Praefix fuer die kurzlebige Klartextkopie aus
     * withDecryptedCopy(). Liegt bewusst unter dem Praefix, damit deletePrefix()
     * und der TTL-Cleanup auch Reste eines abgestuerzten Prozesses entfernen.
     */
    private const WORK_DIR = 'arbeit';

    private const COPY_BLOCK_BYTES = 1024 * 1024;

    private readonly TemporaryUploadKeyring $keyring;

    public function __construct(?TemporaryUploadKeyring $keyring = null)
    {
        $this->keyring = $keyring ?? new TemporaryUploadKeyring(TemporaryUploadCipherFactory::make());
    }

    /**
     * Der konkrete Adaptertyp ist bewusst festgelegt: Die Loeschung eines
     * gesamten Praefixes verlangt Verzeichniszugriff, und die Chiffrate werden
     * ueber absolute Pfade geschrieben und gelesen. Die Disk ist deshalb immer
     * lokal.
     */
    public function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Erzeugt ein neues zufaelliges Praefix fuer einen Upload und dazu einen
     * zufaelligen Dateischluessel. Das Praefix enthaelt bewusst keinen Bezug zu
     * Nutzer, Mandant, Dokument oder Dateiname.
     */
    public function newPrefix(): string
    {
        $prefix = self::ROOT.'/'.Str::lower(Str::ulid()->toBase32()).Str::random(24);

        $this->keyring->create($prefix);

        return $prefix;
    }

    /**
     * Umhuellter Dateischluessel fuer temporary_uploads.encryption_key_wrapped.
     * Nur dieser Wert wird gespeichert, niemals der Klartextschluessel.
     */
    public function wrappedKeyFor(string $prefix): string
    {
        return $this->keyring->wrappedKeyFor($prefix);
    }

    public function chunkKey(string $prefix, int $index): string
    {
        return sprintf('%s/%s/%06d.part', $prefix, TemporaryFileKind::CHUNK->value, $index);
    }

    public function originalKey(string $prefix): string
    {
        return sprintf('%s/%s.bin', $prefix, TemporaryFileKind::ORIGINAL->value);
    }

    /**
     * Pfad einer Ableitung. Der Name wird auf harmlose Zeichen begrenzt, damit
     * ein Aufrufer keinen Originaldateinamen und keinen Pfad einschmuggeln
     * kann.
     */
    public function derivativeKey(string $prefix, TemporaryFileKind $kind, string $name): string
    {
        $safe = preg_replace('/[^a-z0-9._-]/i', '', $name);
        $safe = is_string($safe) ? trim($safe, '.-_') : '';

        if ($safe === '') {
            $safe = Str::random(16);
        }

        return sprintf('%s/%s/%s', $prefix, $kind->value, $safe);
    }

    /**
     * Schreibt einen Dateiabschnitt. Idempotent: ein bereits vorhandener
     * Abschnitt gleicher Klartextgroesse wird nicht erneut geschrieben.
     *
     * @return bool true, wenn der Abschnitt neu geschrieben wurde
     */
    public function putChunk(string $prefix, int $index, string $contents): bool
    {
        $key = $this->chunkKey($prefix, $index);

        if ($this->exists($key) && $this->size($key) === strlen($contents)) {
            return false;
        }

        $this->put($key, $contents);

        return true;
    }

    /**
     * Schreibt einen Dateiabschnitt aus einem temporaeren Pfad, ohne ihn
     * vollstaendig in den Speicher zu laden.
     *
     * @return bool true, wenn der Abschnitt neu geschrieben wurde
     */
    public function putChunkFromPath(string $prefix, int $index, string $sourcePath): bool
    {
        $key = $this->chunkKey($prefix, $index);
        $size = is_file($sourcePath) ? (int) filesize($sourcePath) : 0;

        if ($this->exists($key) && $this->size($key) === $size) {
            return false;
        }

        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Der uebertragene Dateiabschnitt konnte nicht gelesen werden.');
        }

        try {
            $this->putStream($key, $stream);
        } finally {
            fclose($stream);
        }

        return true;
    }

    public function hasChunk(string $prefix, int $index): bool
    {
        return $this->exists($this->chunkKey($prefix, $index));
    }

    /**
     * @return list<int>
     */
    public function receivedChunkIndexes(string $prefix, int $totalChunks): array
    {
        $received = [];

        for ($index = 0; $index < $totalChunks; $index++) {
            if ($this->hasChunk($prefix, $index)) {
                $received[] = $index;
            }
        }

        return $received;
    }

    /**
     * @return list<int>
     */
    public function missingChunkIndexes(string $prefix, int $totalChunks): array
    {
        $missing = [];

        for ($index = 0; $index < $totalChunks; $index++) {
            if (! $this->hasChunk($prefix, $index)) {
                $missing[] = $index;
            }
        }

        return $missing;
    }

    /**
     * Tatsaechlich empfangene Klartextbytes, ermittelt aus den Vorspaennen auf
     * der Platte. Der Wert ist damit auch nach einem Abbruch belastbar und
     * macht die Chunkannahme idempotent.
     */
    public function receivedBytes(string $prefix, int $totalChunks): int
    {
        $bytes = 0;

        for ($index = 0; $index < $totalChunks; $index++) {
            $key = $this->chunkKey($prefix, $index);

            if ($this->exists($key)) {
                $bytes += $this->size($key);
            }
        }

        return $bytes;
    }

    public function exists(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    /**
     * Klartextgroesse einer Datei laut Vorspann des Chiffrats, 0 wenn die
     * Datei nicht existiert.
     */
    public function size(string $key): int
    {
        if (! $this->exists($key)) {
            return 0;
        }

        $handle = fopen($this->absolutePath($key), 'rb');

        if ($handle === false) {
            return 0;
        }

        try {
            return CiphertextHeader::read($handle)->plaintextLength;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Absoluter Pfad des CHIFFRATS auf der lokalen Disk. Der Inhalt unter
     * diesem Pfad ist verschluesselt; Pruefer erhalten den Klartext ueber
     * source(), readStream() oder withDecryptedCopy().
     */
    public function absolutePath(string $key): string
    {
        return $this->disk()->path($key);
    }

    /**
     * Pfad der HEIC-Konvertierung. Fest benannt, damit die auswertende Schicht
     * die umgewandelte Datei ohne zusaetzliche Datenbankspalte findet.
     */
    public function convertedImageKey(string $prefix): string
    {
        return $this->derivativeKey($prefix, TemporaryFileKind::KONVERTIERUNG, 'konvertiert.jpg');
    }

    /**
     * Pfad eines aus einem Archiv entpackten Eintrags. Der Name wird vom System
     * vergeben, der Eintragsname des Archivs wird verworfen.
     */
    public function archiveEntryKey(string $prefix, int $index, string $extension): string
    {
        return $this->derivativeKey(
            $prefix,
            TemporaryFileKind::ARCHIV_EINTRAG,
            sprintf('%04d.%s', $index, $extension)
        );
    }

    public function putDerivative(string $prefix, TemporaryFileKind $kind, string $name, string $contents): string
    {
        $key = $this->derivativeKey($prefix, $kind, $name);

        $this->put($key, $contents);

        return $key;
    }

    /**
     * Schreibt einen Inhalt verschluesselt. Nur fuer Inhalte, die ohnehin im
     * Speicher liegen (Abschnitte, Konvertierungen, kleine Ableitungen).
     *
     * @return int geschriebene Klartextbytes
     */
    public function put(string $key, string $contents): int
    {
        $writer = $this->openWriter($key);

        try {
            $writer->write($contents);

            return $writer->finish();
        } catch (Throwable $exception) {
            $writer->abort();

            throw $exception;
        }
    }

    /**
     * Schreibt einen Klartextstrom blockweise verschluesselt.
     *
     * @param  resource  $source
     * @return int geschriebene Klartextbytes
     */
    public function putStream(string $key, $source): int
    {
        $writer = $this->openWriter($key);

        try {
            while (! feof($source)) {
                $block = fread($source, self::COPY_BLOCK_BYTES);

                if ($block === false) {
                    throw new RuntimeException('Der Quellstrom konnte nicht gelesen werden.');
                }

                if ($block !== '') {
                    $writer->write($block);
                }
            }

            return $writer->finish();
        } catch (Throwable $exception) {
            $writer->abort();

            throw $exception;
        }
    }

    /**
     * Oeffnet einen verschluesselnden Schreibvorgang. Der Aufrufer schreibt
     * Klartextbloecke und schliesst mit finish() oder verwirft mit abort().
     */
    public function openWriter(string $key): QuarantineFileWriter
    {
        $prefix = $this->prefixOf($key);
        $fileKey = $this->keyring->fileKeyForWriting($prefix);

        // Die Datei wird zuerst leer angelegt, damit das Verzeichnis der Disk
        // existiert und der Schreibzugriff ueber den absoluten Pfad moeglich ist.
        $this->disk()->put($key, '');

        $handle = fopen($this->absolutePath($key), 'w+b');

        if ($handle === false) {
            throw new RuntimeException('Die Zieldatei im Kurzzeitbereich konnte nicht geoeffnet werden.');
        }

        $cipher = $this->keyring->cipher();

        return new QuarantineFileWriter(
            $cipher->openWriter($handle, $fileKey),
            fn () => $this->disk()->delete($key),
        );
    }

    /**
     * Entschluesselter, sequentieller Klartextstrom. Der Klartext entsteht
     * nur im Arbeitsspeicher, blockweise. Manipulationen fuehren beim Lesen
     * zu einer CipherIntegrityException.
     *
     * @return resource
     *
     * @throws UploadRejectedException wenn die Datei nicht vorhanden ist
     * @throws CipherIntegrityException wenn Vorspann oder Schluessel nicht
     *                                  passen; weitere Integritaetsfehler
     *                                  werden beim Lesen aus dem Strom geworfen
     */
    public function readStream(string $key)
    {
        if (! $this->exists($key)) {
            throw UploadRejectedException::because(UploadErrorCode::QUELLE_NICHT_VORHANDEN);
        }

        $fileKey = $this->keyring->fileKeyForReading($this->prefixOf($key));

        $handle = fopen($this->absolutePath($key), 'rb');

        if ($handle === false) {
            throw UploadRejectedException::because(UploadErrorCode::QUELLE_NICHT_VORHANDEN);
        }

        try {
            $header = CiphertextHeader::read($handle);
            rewind($handle);

            $reader = TemporaryUploadCipherFactory::byId($header->cipherId)->openReader($handle, $fileKey);
        } catch (Throwable $exception) {
            fclose($handle);

            throw $exception;
        }

        return PlaintextStreamWrapper::open($reader);
    }

    /**
     * Vollstaendiger Klartext im Speicher. Nur fuer die Uebergabe an den
     * KI-Provider und fuer kleine Inhalte, die ohnehin als Ganzes benoetigt
     * werden.
     *
     * @throws UploadRejectedException
     * @throws CipherIntegrityException
     */
    public function read(string $key): string
    {
        $stream = $this->readStream($key);

        try {
            $contents = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if ($contents === false) {
            throw new RuntimeException('Der Klartextstrom konnte nicht gelesen werden.');
        }

        return $contents;
    }

    public function source(string $key): EncryptedFileSource
    {
        return new EncryptedFileSource($this, $key);
    }

    /**
     * AUSNAHME: Stellt den Klartext fuer die Dauer des Aufrufs als lokale Datei
     * bereit. Einzige Verwendung ist ZipArchive (ArchiveGuard, PageCounter fuer
     * XLSX, ExpandArchive), das ausschliesslich ueber Dateipfade arbeitet und
     * keinen Strom akzeptiert.
     *
     * Die Kopie liegt unter <praefix>/arbeit/ mit Rechten 0600, wird nach dem
     * Aufruf mit Nullbytes ueberschrieben und geloescht. Bricht der Prozess
     * ab, entfernt deletePrefix() beziehungsweise der TTL-Cleanup den Rest.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public function withDecryptedCopy(string $key, callable $callback): mixed
    {
        $prefix = $this->prefixOf($key);
        $workKey = sprintf('%s/%s/%s.tmp', $prefix, self::WORK_DIR, Str::random(24));

        $this->disk()->put($workKey, '');

        $path = $this->absolutePath($workKey);

        @chmod($path, 0600);

        $target = fopen($path, 'wb');

        if ($target === false) {
            $this->disk()->delete($workKey);

            throw new RuntimeException('Die Arbeitskopie konnte nicht angelegt werden.');
        }

        try {
            $source = $this->readStream($key);

            try {
                while (! feof($source)) {
                    $block = fread($source, self::COPY_BLOCK_BYTES);

                    if ($block !== false && $block !== '') {
                        fwrite($target, $block);
                    }
                }
            } finally {
                fclose($source);
                fclose($target);
            }

            return $callback($path);
        } finally {
            $this->destroyWorkFile($workKey, $path);
        }
    }

    /**
     * Entfernt ausschliesslich die Dateiabschnitte. Wird nach erfolgreicher
     * Zusammensetzung aufgerufen, damit der Kurzzeitbereich nicht die doppelte
     * Datenmenge haelt.
     */
    public function deleteChunks(string $prefix): void
    {
        $this->disk()->deleteDirectory($prefix.'/'.TemporaryFileKind::CHUNK->value);
    }

    /**
     * Loescht ALLES zu diesem Upload: Originaldatei, Dateiabschnitte,
     * Seitenbilder, Konvertierungen, Textauszuege, entpackte Archiveintraege
     * und eine etwaige Arbeitskopie. Der Dateischluessel wird aus dem
     * Prozesscache entfernt.
     *
     * @return bool true, wenn danach nichts mehr vorhanden ist
     */
    public function deletePrefix(string $prefix): bool
    {
        if ($prefix === '' || ! str_starts_with($prefix, self::ROOT.'/')) {
            throw UploadRejectedException::because(UploadErrorCode::QUELLE_NICHT_VORHANDEN);
        }

        $disk = $this->disk();

        // Ein bereits geloeschtes Praefix gilt als erfolgreich geloescht, weil
        // der Zielzustand erreicht ist. Das haelt den Vorgang idempotent.
        $disk->deleteDirectory($prefix);

        $this->keyring->forget($prefix);

        return $this->countFiles($prefix) === 0;
    }

    /**
     * Anzahl der noch vorhandenen Dateien unter einem Praefix. Grundlage des
     * Loeschnachweises und der Testpruefung.
     */
    public function countFiles(string $prefix): int
    {
        return count($this->disk()->allFiles($prefix));
    }

    /**
     * Alle Praefixe, die derzeit auf der Platte liegen. Grundlage der Suche
     * nach verwaisten Resten ohne Datensatz.
     *
     * @return list<string>
     */
    public function allPrefixes(): array
    {
        return array_values($this->disk()->directories(self::ROOT));
    }

    /**
     * Juengster Aenderungszeitpunkt einer Datei unter dem Praefix als
     * Unix-Zeit, null wenn keine Datei vorhanden ist.
     */
    public function lastModifiedAt(string $prefix): ?int
    {
        $latest = null;

        foreach ($this->disk()->allFiles($prefix) as $file) {
            $modified = $this->disk()->lastModified($file);

            if ($latest === null || $modified > $latest) {
                $latest = $modified;
            }
        }

        return $latest;
    }

    /**
     * Praefix, also die ersten beiden Pfadsegmente, eines Schluessels.
     */
    private function prefixOf(string $key): string
    {
        $segments = explode('/', $key);

        if (count($segments) < 3 || $segments[0] !== self::ROOT || $segments[1] === '') {
            throw UploadRejectedException::because(UploadErrorCode::QUELLE_NICHT_VORHANDEN);
        }

        return $segments[0].'/'.$segments[1];
    }

    /**
     * Ueberschreibt die Arbeitskopie mit Nullbytes und loescht sie. Das
     * Ueberschreiben ist auf SSD- oder Netzspeicher kein forensischer Schutz
     * (ARCHITECTURE.md 5.3), verhindert aber, dass ein spaeteres Listing des
     * Verzeichnisses Klartext zeigt, falls das Loeschen selbst scheitert.
     */
    private function destroyWorkFile(string $workKey, string $path): void
    {
        if (is_file($path)) {
            $size = (int) filesize($path);
            $handle = fopen($path, 'r+b');

            if ($handle !== false) {
                $zeros = str_repeat("\0", min($size, self::COPY_BLOCK_BYTES));

                for ($written = 0; $written < $size; $written += strlen($zeros)) {
                    fwrite($handle, substr($zeros, 0, min(strlen($zeros), $size - $written)));
                }

                fflush($handle);
                fclose($handle);
            }
        }

        $this->disk()->delete($workKey);

        $workDir = dirname($workKey);

        if ($this->disk()->files($workDir) === []) {
            $this->disk()->deleteDirectory($workDir);
        }
    }
}
