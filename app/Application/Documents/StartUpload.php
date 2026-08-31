<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Dto\StartUploadCommand;
use App\Application\Documents\Dto\UploadSession;
use App\Application\Documents\Support\SourceLabelFactory;
use App\Enums\DeletionStatus;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\MimeGuard;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use App\Services\Storage\UploadLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Use Case: Chunk-Upload starten.
 *
 * Legt das Dokument mit neutraler Quellenbezeichnung und den Kurzzeitdatensatz
 * an, vergibt ein zufaelliges Ablagepraefix und setzt die Kurzzeit-TTL.
 *
 * VERBINDLICHE PUNKTE:
 * - Die TTL beginnt mit dem Eingang des ERSTEN Abschnitts, nicht mit dem
 *   Abschluss des Uploads (Abschnitt 19). first_chunk_at bleibt hier deshalb
 *   noch leer, expires_at wird bereits vorbelegt, damit ein nie begonnener
 *   Upload ebenfalls vom Cleanup erfasst wird.
 * - Geprueft wird bereits hier die Dateiendung und das Volumen, damit eine
 *   unzulaessige Datei nicht erst nach vollstaendiger Uebertragung abgelehnt
 *   wird.
 * - Der Originaldateiname erreicht diesen Use Case nicht. Uebergeben wird nur
 *   die Endung (siehe StartUploadCommand).
 */
final class StartUpload
{
    public function __construct(
        private readonly TemporaryUploadStorage $storage,
        private readonly MimeGuard $mimeGuard,
        private readonly SourceLabelFactory $labels,
    ) {}

    /**
     * @throws UploadRejectedException
     */
    public function __invoke(BillingRun $billingRun, StartUploadCommand $command, ?UploadLimits $limits = null): UploadSession
    {
        $limits ??= UploadLimits::fromConfig();

        $extension = $this->mimeGuard->assertExtensionAllowed($command->extension);

        if ($command->byteSize <= 0) {
            throw UploadRejectedException::because(UploadErrorCode::DATEI_LEER);
        }

        if ($command->byteSize > $limits->maxFileBytes) {
            throw UploadRejectedException::withContext(UploadErrorCode::DATEI_ZU_GROSS, [
                'byte_size' => $command->byteSize,
                'limit' => $limits->maxFileBytes,
            ]);
        }

        $quota = new UploadQuota($limits);

        if (! $quota->fitsInRun($billingRun, $command->byteSize)) {
            throw UploadRejectedException::withContext(UploadErrorCode::LAUF_LIMIT_ERREICHT, [
                'verbraucht' => $quota->usedBytes($billingRun),
                'limit' => $limits->maxRunBytes,
            ]);
        }

        $totalChunks = $limits->expectedChunkCount($command->byteSize);
        $ttlMinutes = $this->ttlMinutes();
        $expiresAt = Carbon::now()->addMinutes($ttlMinutes);

        /** @var array{Document, TemporaryUpload} $created */
        $created = DB::transaction(function () use ($billingRun, $command, $extension, $totalChunks, $expiresAt): array {
            $document = $this->createDocument($billingRun, $command, $extension);
            $upload = $this->createUpload($document, $command, $totalChunks, $expiresAt);

            return [$document, $upload];
        });

        return new UploadSession($created[0], $created[1], $totalChunks, $limits->chunkBytes, $expiresAt);
    }

    private function createDocument(BillingRun $billingRun, StartUploadCommand $command, string $extension): Document
    {
        $sequence = $this->nextSequenceNumber($billingRun);
        $type = $command->suggestedType;

        $label = $type instanceof DocumentType
            ? $this->labels->forType($sequence, $type)
            : $this->labels->pending($sequence);

        $mimeTypes = $this->mimeGuard->mimeTypesForExtension($extension);

        $document = new Document;

        $document->fill([
            'organization_id' => $billingRun->getAttribute('organization_id'),
            'billing_run_id' => $billingRun->getKey(),
            'sequence_number' => $sequence,
            'source_label' => $this->labels->truncate($label),
            'document_type' => $type ?? DocumentType::SONSTIGES,
            'document_type_confidence' => null,
            'type_assigned_manually' => $type instanceof DocumentType,
            'mime_type' => $mimeTypes[0] ?? null,
            'original_byte_size' => $command->byteSize,
            'page_count' => null,
            'processing_status' => DocumentProcessingStatus::HOCHGELADEN,
            'deletion_status' => DeletionStatus::OFFEN,
        ]);

        $document->save();

        return $document;
    }

    private function createUpload(
        Document $document,
        StartUploadCommand $command,
        int $totalChunks,
        Carbon $expiresAt,
    ): TemporaryUpload {
        $upload = new TemporaryUpload;

        $upload->fill([
            'organization_id' => $document->getAttribute('organization_id'),
            'document_id' => $document->getKey(),
            'storage_disk' => TemporaryUploadStorage::DISK,
            'storage_key' => $this->storage->newPrefix(),
            'byte_size' => $command->byteSize,
            'total_chunks' => $totalChunks,
            'received_chunks' => 0,
            'received_bytes' => 0,
            'first_chunk_at' => null,
            'expires_at' => $expiresAt,
            'deletion_attempts' => 0,
            'is_tombstone' => false,
            'provider_deletion_status' => DeletionStatus::NICHT_ERFORDERLICH,
        ]);

        $upload->save();

        return $upload;
    }

    /**
     * Laufende Nummer im Abrechnungslauf. Die Eindeutigkeit sichert zusaetzlich
     * ein Unique-Index; die Ermittlung laeuft daher in derselben Transaktion.
     */
    private function nextSequenceNumber(BillingRun $billingRun): int
    {
        $max = Document::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->max('sequence_number');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /**
     * Kurzzeit-TTL in Minuten. Der Wert ist in der Konfiguration hart auf 120
     * Minuten begrenzt, damit eine fehlerhafte .env das Loeschkonzept nicht
     * aufweicht.
     */
    private function ttlMinutes(): int
    {
        $value = config('smartabrechnen.retention.temp_upload_ttl_minutes');
        $minutes = is_numeric($value) ? (int) $value : 120;

        return min(120, max(1, $minutes));
    }
}
