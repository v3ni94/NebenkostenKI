<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Documents\FailDocument;
use App\Enums\DocumentProcessingStatus;
use App\Jobs\Concerns\ResolvesDocumentFromPayload;
use App\Models\Document;
use App\Models\ProcessingJob;
use App\Services\Queue\DeadLetterListener;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Schliesst das Dokument ab, wenn sein Teiljob endgueltig in den
 * Dead-Letter-Status geht.
 *
 * Ohne diesen Abschluss bliebe ein Dokument nach einem abgelaufenen Lease oder
 * einer unerwarteten Ausnahme im letzten Versuch in KLASSIFIZIERUNG oder
 * EXTRAKTION stehen, waehrend die Quelldatei bis zur Kurzzeit-TTL im
 * Kurzzeitbereich liegt. Abschnitt 6.3 Schritt 16 verlangt stattdessen die
 * sofortige Loeschung bei endgueltigem Fehler.
 *
 * IDEMPOTENT: Ein Dokument in einem Endzustand wird nicht angefasst. Der
 * Fehlercode des Jobs wird uebernommen, sofern er als Endergebnis taugt;
 * ein abgelaufenes Lease wird als unerwarteter Fehler ausgewiesen, weil
 * dessen Meldung eine erneute Einplanung verspraeche, die nicht stattfindet.
 */
final class FailDocumentOnDeadLetter implements DeadLetterListener
{
    use ResolvesDocumentFromPayload;

    public function __construct(private readonly FailDocument $failDocument) {}

    public function deadLettered(ProcessingJob $job): void
    {
        try {
            $document = $this->documentFrom($job);

            if (! $document instanceof Document) {
                return;
            }

            $status = $document->getAttribute('processing_status');

            if ($status instanceof DocumentProcessingStatus && $status->requiresSourceDeletion()) {
                return;
            }

            ($this->failDocument)($document, $this->errorCodeFor($job));
        } catch (Throwable) {
            // Ohne Meldung: der Abschluss darf den Queue-Lauf nicht abbrechen
            // und keine Inhalte protokollieren. Der TTL-Cleanup bleibt als
            // letzte Sicherung bestehen.
            Log::warning('Dokument konnte nach Dead Letter nicht abgeschlossen werden', [
                'job_id' => (string) $job->getKey(),
            ]);
        }
    }

    private function errorCodeFor(ProcessingJob $job): UploadErrorCode
    {
        $code = UploadErrorCode::tryFrom((string) $job->getAttribute('error_code'));

        if ($code === null || $code === UploadErrorCode::LEASE_ABGELAUFEN) {
            return UploadErrorCode::UNERWARTETER_FEHLER;
        }

        return $code;
    }
}
