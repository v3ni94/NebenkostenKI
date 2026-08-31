<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Dto\CleanupReport;
use App\Application\Documents\Dto\DeletionReason;
use App\Enums\DocumentProcessingStatus;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Queue\DatabaseJobQueue;
use App\Services\Storage\UploadErrorCode;

/**
 * Use Case: unabhaengiger TTL-Cleanup (Abschnitt 6.3 Schritt 17,
 * Abschnitt 19).
 *
 * VERBINDLICHE EIGENSCHAFTEN:
 *
 * 1. UNABHAENGIG. Der Lauf haengt nicht an der Verarbeitung, nicht am
 *    KI-Provider und nicht an einem laufenden Worker. Er loescht auch dann,
 *    wenn ein Job haengen geblieben ist, ein Browserfenster geschlossen wurde
 *    oder ein Providerabruf nie zurueckkam.
 * 2. IDEMPOTENT. Ein zweiter Lauf findet nichts mehr und aendert nichts. Die
 *    Auswahl greift nur auf Datensaetze ohne Tombstone; nach erfolgreicher
 *    Loeschung sind sie Tombstone und damit unsichtbar fuer den naechsten Lauf.
 * 3. STAPELWEISE. Ein Lauf ist auf eine Hoechstzahl begrenzt, damit er in
 *    einen Cron-Lauf mit begrenzter Laufzeit passt. Der Rest folgt im
 *    naechsten Intervall.
 *
 * Die TTL beginnt mit dem Eingang des ersten Abschnitts und ist in der
 * Konfiguration hart auf 120 Minuten begrenzt.
 */
final class CleanupExpiredUploads
{
    public const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private readonly DeleteOriginalSources $deleteSources,
        private readonly DatabaseJobQueue $queue,
    ) {}

    public function __invoke(int $batchSize = self::DEFAULT_BATCH_SIZE): CleanupReport
    {
        $expired = TemporaryUpload::query()
            ->expired()
            ->orderBy('expires_at')
            ->limit(max(1, $batchSize))
            ->get();

        $inspected = 0;
        $deleted = 0;
        $failed = 0;
        $alreadyDeleted = 0;
        $cancelledJobs = 0;

        foreach ($expired as $upload) {
            $inspected++;

            $document = Document::query()->whereKey($upload->getAttribute('document_id'))->first();

            if (! $document instanceof Document) {
                // Das Dokument wurde bereits entfernt. Die Quelldatei darf
                // trotzdem nicht liegen bleiben.
                $upload->forceFill(['is_tombstone' => true, 'storage_key' => null])->save();
                $alreadyDeleted++;

                continue;
            }

            $cancelledJobs += $this->queue->cancelForDocument((string) $document->getKey());

            $this->markAborted($document);

            $outcome = ($this->deleteSources)($document, DeletionReason::TTL_ABGELAUFEN);

            if ($outcome->alreadyDeleted) {
                $alreadyDeleted++;
            } elseif ($outcome->isSuccessful()) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        return new CleanupReport($inspected, $deleted, $failed, $alreadyDeleted, $cancelledJobs);
    }

    /**
     * Ein noch laufendes Dokument gilt als abgebrochen. Ein bereits
     * abgeschlossenes Dokument behaelt seinen Status: dort ist die Loeschung
     * nur nachgelaufen, die Auswertung war erfolgreich.
     */
    private function markAborted(Document $document): void
    {
        $status = $document->getAttribute('processing_status');

        if ($status instanceof DocumentProcessingStatus && $status->requiresSourceDeletion()) {
            return;
        }

        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::ABGEBROCHEN,
            'failure_code' => UploadErrorCode::TTL_ABGELAUFEN->value,
            'failure_message' => mb_substr(UploadErrorCode::TTL_ABGELAUFEN->message(), 0, 500),
        ])->save();
    }
}
