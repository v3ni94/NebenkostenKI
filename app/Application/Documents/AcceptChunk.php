<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Dto\ChunkAcceptanceResult;
use App\Models\TemporaryUpload;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use App\Services\Storage\UploadLimits;
use Illuminate\Support\Carbon;

/**
 * Use Case: einen Dateiabschnitt annehmen.
 *
 * IDEMPOTENZ, verbindlich: Der Zaehlerstand wird nach jeder Annahme aus der
 * Platte neu ermittelt, nicht hochgezaehlt. Ein doppelt gesendeter Abschnitt
 * aendert damit nichts, und eine Wiederaufnahme nach Verbindungsabbruch
 * uebertraegt nur die fehlenden Abschnitte.
 *
 * TTL: Mit dem ersten Abschnitt beginnt die Kurzzeit-TTL. Ab diesem Moment
 * loescht der unabhaengige Cleanup-Job spaetestens nach
 * TEMP_UPLOAD_TTL_MINUTES, auch wenn die Verarbeitung haengen bleibt
 * (Abschnitt 19).
 *
 * DATENSCHUTZ: Der Abschnitt wird direkt vom temporaeren Pfad des Webservers in
 * den Quarantaenebereich geschrieben. Der Inhalt wird nicht in eine Variable
 * geladen, die spaeter protokolliert werden koennte, und nicht auf eine andere
 * Disk kopiert.
 */
final class AcceptChunk
{
    public function __construct(private readonly TemporaryUploadStorage $storage) {}

    /**
     * @param  string  $sourcePath  temporaerer Pfad des uebertragenen Abschnitts
     *
     * @throws UploadRejectedException
     */
    public function __invoke(
        TemporaryUpload $upload,
        int $index,
        string $sourcePath,
        ?UploadLimits $limits = null,
    ): ChunkAcceptanceResult {
        $limits ??= UploadLimits::fromConfig();

        $this->assertUsable($upload);

        $totalChunks = (int) $upload->getAttribute('total_chunks');

        if ($index < 0 || $index >= $totalChunks) {
            throw UploadRejectedException::withContext(UploadErrorCode::CHUNK_INDEX_UNGUELTIG, [
                'index' => $index,
                'abschnitte' => $totalChunks,
            ]);
        }

        $prefix = $this->prefixOf($upload);
        $chunkSize = is_file($sourcePath) ? (int) filesize($sourcePath) : 0;

        if ($chunkSize <= 0) {
            throw UploadRejectedException::because(UploadErrorCode::DATEI_LEER);
        }

        if ($chunkSize > $limits->chunkBytes * 2) {
            // Doppelte Abschnittsgroesse als Toleranz, danach ist die Angabe
            // des Browsers nicht mehr plausibel.
            throw UploadRejectedException::withContext(UploadErrorCode::CHUNK_ANZAHL_UNGUELTIG, [
                'abschnittsgroesse' => $chunkSize,
                'limit' => $limits->chunkBytes,
            ]);
        }

        $alreadyPresent = $this->storage->hasChunk($prefix, $index);

        $written = $this->storage->putChunkFromPath($prefix, $index, $sourcePath);

        $receivedBytes = $this->storage->receivedBytes($prefix, $totalChunks);

        if ($receivedBytes > $limits->maxFileBytes) {
            // Der Browser hat mehr uebertragen als angekuendigt. Der Upload
            // wird verworfen, damit der Quarantaenebereich nicht als Speicher
            // missbraucht wird.
            $this->storage->deletePrefix($prefix);

            throw UploadRejectedException::withContext(UploadErrorCode::DATEI_ZU_GROSS, [
                'byte_size' => $receivedBytes,
                'limit' => $limits->maxFileBytes,
            ]);
        }

        $received = $this->storage->receivedChunkIndexes($prefix, $totalChunks);
        $missing = $this->storage->missingChunkIndexes($prefix, $totalChunks);

        $attributes = [
            'received_chunks' => count($received),
            'received_bytes' => $receivedBytes,
        ];

        if ($upload->getAttribute('first_chunk_at') === null) {
            $now = Carbon::now();
            $attributes['first_chunk_at'] = $now;
            $attributes['expires_at'] = $now->copy()->addMinutes($this->ttlMinutes());
        }

        $upload->forceFill($attributes)->save();

        return new ChunkAcceptanceResult(
            count($received),
            $totalChunks,
            $receivedBytes,
            $missing,
            $alreadyPresent && ! $written,
        );
    }

    /**
     * @throws UploadRejectedException
     */
    private function assertUsable(TemporaryUpload $upload): void
    {
        if ($upload->getAttribute('is_tombstone') === true) {
            throw UploadRejectedException::because(UploadErrorCode::UPLOAD_ABGELAUFEN);
        }

        $expiresAt = $upload->getAttribute('expires_at');

        if ($expiresAt instanceof Carbon && $expiresAt->isPast()) {
            throw UploadRejectedException::because(UploadErrorCode::UPLOAD_ABGELAUFEN);
        }
    }

    /**
     * @throws UploadRejectedException
     */
    private function prefixOf(TemporaryUpload $upload): string
    {
        $prefix = $upload->getAttribute('storage_key');

        if (! is_string($prefix) || $prefix === '') {
            throw UploadRejectedException::because(UploadErrorCode::UPLOAD_ABGELAUFEN);
        }

        return $prefix;
    }

    private function ttlMinutes(): int
    {
        $value = config('smartabrechnen.retention.temp_upload_ttl_minutes');
        $minutes = is_numeric($value) ? (int) $value : 120;

        return min(120, max(1, $minutes));
    }
}
