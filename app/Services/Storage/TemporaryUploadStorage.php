<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Storage\Exceptions\UploadRejectedException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

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
 */
final class TemporaryUploadStorage
{
    public const DISK = 'temporary_uploads';

    /**
     * Wurzelverzeichnis innerhalb der Disk. Ein sprechender Name erleichtert
     * die Betriebspruefung, ohne Inhalte zu verraten.
     */
    private const ROOT = 'quarantaene';

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Erzeugt ein neues zufaelliges Praefix fuer einen Upload. Es enthaelt
     * bewusst keinen Bezug zu Nutzer, Mandant, Dokument oder Dateiname.
     */
    public function newPrefix(): string
    {
        return self::ROOT.'/'.Str::lower(Str::ulid()->toBase32()).Str::random(24);
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
     * Abschnitt gleicher Groesse wird nicht erneut geschrieben.
     *
     * @return bool true, wenn der Abschnitt neu geschrieben wurde
     */
    public function putChunk(string $prefix, int $index, string $contents): bool
    {
        $key = $this->chunkKey($prefix, $index);

        if ($this->disk()->exists($key) && $this->disk()->size($key) === strlen($contents)) {
            return false;
        }

        $this->disk()->put($key, $contents);

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

        if ($this->disk()->exists($key) && $this->disk()->size($key) === $size) {
            return false;
        }

        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Der uebertragene Dateiabschnitt konnte nicht gelesen werden.');
        }

        try {
            $this->disk()->put($key, $stream);
        } finally {
            fclose($stream);
        }

        return true;
    }

    public function hasChunk(string $prefix, int $index): bool
    {
        return $this->disk()->exists($this->chunkKey($prefix, $index));
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
     * Tatsaechlich empfangene Bytes, ermittelt aus der Platte. Der Wert ist
     * damit auch nach einem Abbruch belastbar und macht die Chunkannahme
     * idempotent.
     */
    public function receivedBytes(string $prefix, int $totalChunks): int
    {
        $bytes = 0;

        for ($index = 0; $index < $totalChunks; $index++) {
            $key = $this->chunkKey($prefix, $index);

            if ($this->disk()->exists($key)) {
                $bytes += $this->disk()->size($key);
            }
        }

        return $bytes;
    }

    public function exists(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    public function size(string $key): int
    {
        return $this->disk()->exists($key) ? $this->disk()->size($key) : 0;
    }

    /**
     * Absoluter Pfad auf der lokalen Disk. Wird fuer Magic-Byte-, Struktur-
     * und Malwarepruefung benoetigt, die auf Dateien arbeiten.
     */
    public function absolutePath(string $key): string
    {
        $disk = $this->disk();

        if (! method_exists($disk, 'path')) {
            throw new RuntimeException(
                'Die Disk "'.self::DISK.'" muss lokal sein, damit Struktur- und Malwarepruefung moeglich sind.'
            );
        }

        return $disk->path($key);
    }

    public function putDerivative(string $prefix, TemporaryFileKind $kind, string $name, string $contents): string
    {
        $key = $this->derivativeKey($prefix, $kind, $name);

        $this->disk()->put($key, $contents);

        return $key;
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
     * Seitenbilder, Konvertierungen, Textauszuege und entpackte Archiveintraege.
     *
     * @return bool true, wenn danach nichts mehr vorhanden ist
     */
    public function deletePrefix(string $prefix): bool
    {
        if ($prefix === '' || ! str_starts_with($prefix, self::ROOT.'/')) {
            throw UploadRejectedException::because(UploadErrorCode::QUELLE_NICHT_VORHANDEN);
        }

        $disk = $this->disk();

        if (! $disk->directoryExists($prefix)) {
            // Bereits geloescht. Die Loeschung ist damit erfolgreich, weil der
            // Zielzustand erreicht ist. Das haelt den Vorgang idempotent.
            return true;
        }

        $disk->deleteDirectory($prefix);

        return ! $disk->directoryExists($prefix)
            && $disk->files($prefix) === []
            && ! $disk->exists($this->originalKey($prefix));
    }

    /**
     * Anzahl der noch vorhandenen Dateien unter einem Praefix. Grundlage des
     * Loeschnachweises und der Testpruefung.
     */
    public function countFiles(string $prefix): int
    {
        return count($this->disk()->allFiles($prefix));
    }
}
