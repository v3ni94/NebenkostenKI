<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Storage\Crypto\CipherIntegrityException;
use App\Services\Storage\Exceptions\UploadRejectedException;
use Throwable;

/**
 * Serverseitige Wiederzusammensetzung eines Chunk-Uploads (Abschnitt 6.1).
 *
 * Auf IONOS Webhosting sind post_max_size und upload_max_filesize nicht
 * verlaesslich hoch konfigurierbar. Der Browser uebertraegt deshalb kleine
 * Abschnitte, die hier zu einer Datei zusammengesetzt werden. Der Vorgang ist
 * idempotent und wiederaufnehmbar: Ein Abbruch mitten im Upload fuehrt nur
 * dazu, dass die fehlenden Abschnitte erneut uebertragen werden.
 *
 * DATENSCHUTZ: Die Zusammensetzung laeuft ausschliesslich auf der Disk
 * "temporary_uploads" und blockweise ueber Stroeme. Jeder Abschnitt wird beim
 * Lesen entschluesselt und die Zieldatei beim Schreiben wieder verschluesselt;
 * Klartext existiert nur blockweise im Arbeitsspeicher. Der Inhalt wird nicht
 * als Ganzes in eine Variable geladen und niemals protokolliert.
 */
final class ChunkAssembler
{
    private const COPY_BLOCK_BYTES = 1024 * 1024;

    public function __construct(private readonly TemporaryUploadStorage $storage) {}

    /**
     * @throws UploadRejectedException wenn ein Abschnitt fehlt oder das
     *                                 Ergebnis das Dateilimit ueberschreitet
     * @throws CipherIntegrityException wenn ein Abschnitt auf der Platte
     *                                  manipuliert oder nicht entschluesselbar ist
     */
    public function assemble(string $prefix, int $totalChunks, int $expectedByteSize, UploadLimits $limits): ChunkAssemblyResult
    {
        if ($totalChunks < 1) {
            throw UploadRejectedException::because(UploadErrorCode::CHUNK_ANZAHL_UNGUELTIG);
        }

        $targetKey = $this->storage->originalKey($prefix);

        // Bereits zusammengesetzt: idempotenter Wiedereinstieg nach einem
        // abgebrochenen Cron-Lauf.
        if ($this->storage->exists($targetKey)) {
            $size = $this->storage->size($targetKey);

            if ($size === $expectedByteSize || $expectedByteSize <= 0) {
                return new ChunkAssemblyResult($targetKey, $size, true);
            }

            // Groesse passt nicht: der vorherige Lauf wurde unterbrochen. Die
            // unvollstaendige Datei wird verworfen und neu aufgebaut.
            $this->storage->disk()->delete($targetKey);
        }

        $missing = $this->storage->missingChunkIndexes($prefix, $totalChunks);

        if ($missing !== []) {
            throw UploadRejectedException::withContext(UploadErrorCode::CHUNK_FEHLT, [
                'fehlende_abschnitte' => implode(',', array_slice($missing, 0, 20)),
                'anzahl' => count($missing),
            ]);
        }

        $target = $this->storage->openWriter($targetKey);
        $written = 0;

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $written += $this->appendChunk($target, $prefix, $index);

                if ($written > $limits->maxFileBytes) {
                    throw UploadRejectedException::withContext(UploadErrorCode::DATEI_ZU_GROSS, [
                        'byte_size' => $written,
                        'limit' => $limits->maxFileBytes,
                    ]);
                }
            }

            $written = $target->finish();
        } catch (Throwable $exception) {
            $target->abort();

            throw $exception;
        }

        if ($expectedByteSize > 0 && $written !== $expectedByteSize) {
            $this->storage->disk()->delete($targetKey);

            throw UploadRejectedException::withContext(UploadErrorCode::CHUNK_FEHLT, [
                'geschrieben' => $written,
                'erwartet' => $expectedByteSize,
            ]);
        }

        // Die Abschnitte werden sofort entfernt. Der Kurzzeitbereich haelt
        // damit nur eine Kopie der Datei.
        $this->storage->deleteChunks($prefix);

        return new ChunkAssemblyResult($targetKey, $written, false);
    }

    private function appendChunk(QuarantineFileWriter $target, string $prefix, int $index): int
    {
        try {
            $source = $this->storage->readStream($this->storage->chunkKey($prefix, $index));
        } catch (UploadRejectedException) {
            throw UploadRejectedException::withContext(UploadErrorCode::CHUNK_FEHLT, ['abschnitt' => $index]);
        }

        $written = 0;

        try {
            while (! feof($source)) {
                $block = fread($source, self::COPY_BLOCK_BYTES);

                if ($block === false) {
                    throw UploadRejectedException::withContext(UploadErrorCode::CHUNK_FEHLT, ['abschnitt' => $index]);
                }

                if ($block === '') {
                    continue;
                }

                $target->write($block);

                $written += strlen($block);
            }
        } finally {
            fclose($source);
        }

        return $written;
    }
}
