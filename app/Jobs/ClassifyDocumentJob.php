<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Documents\ClassifyDocument;
use App\Application\Documents\FailDocument;
use App\Application\Documents\Support\ActiveJobHeartbeat;
use App\Enums\DocumentProcessingStatus;
use App\Jobs\Concerns\ResolvesDocumentFromPayload;
use App\Models\Document;
use App\Models\ProcessingJob;
use App\Services\Queue\JobContext;
use App\Services\Queue\JobFailedException;
use App\Services\Queue\ProcessingJobHandler;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\UploadErrorCode;
use Throwable;

/**
 * Teiljob: Dokumentart bestimmen lassen.
 *
 * Ist die KI-Schicht nicht gebunden oder nicht erreichbar, wird der Teiljob
 * mit exponentiellem Backoff wiederholt. Nach dem letzten Versuch gilt der
 * Fehler als endgueltig: Das Dokument wird als fehlgeschlagen gekennzeichnet
 * und die Quelldaten werden sofort geloescht.
 */
final class ClassifyDocumentJob implements ProcessingJobHandler
{
    use ResolvesDocumentFromPayload;

    public function __construct(
        private readonly ClassifyDocument $classifyDocument,
        private readonly FailDocument $failDocument,
        private readonly DocumentPipeline $pipeline,
    ) {}

    public function handle(ProcessingJob $job, JobContext $context): void
    {
        $document = $this->documentFrom($job);

        if (! $document instanceof Document) {
            return;
        }

        $status = $document->getAttribute('processing_status');

        if ($status instanceof DocumentProcessingStatus && $status->requiresSourceDeletion()) {
            return;
        }

        // Heartbeat fuer die KI-Anbindung, siehe ExtractDocumentJob.
        ActiveJobHeartbeat::bind(static fn (): bool => $context->heartbeat());

        try {
            $outcome = ($this->classifyDocument)($document);
        } catch (UploadRejectedException $exception) {
            ($this->failDocument)($document, $exception->errorCode);

            throw JobFailedException::permanent($exception->errorCode);
        } catch (JobFailedException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Unbekannter technischer Fehler, ohne Meldung weitergereicht. Nach
            // dem letzten Versuch wird das Dokument gekennzeichnet und
            // geloescht, statt in KLASSIFIZIERUNG stehen zu bleiben.
            $this->failOnLastAttempt($document, $context);
        } finally {
            ActiveJobHeartbeat::release();
        }

        if ($outcome->documentType !== null) {
            // Auch ein unbestimmter Typ fuehrt weiter: das Dokument bleibt
            // SONSTIGES und wird vom Nutzer manuell zugeordnet. Es wird nichts
            // geraten (Grundsatz 5).
            $this->pipeline->queueExtraction($document);

            return;
        }

        $errorCode = UploadErrorCode::tryFrom($outcome->errorCode ?? '')
            ?? UploadErrorCode::KLASSIFIKATION_FEHLGESCHLAGEN;

        if ($context->isLastAttempt()) {
            ($this->failDocument)($document, $errorCode);

            throw JobFailedException::permanent($errorCode);
        }

        throw JobFailedException::retryable($errorCode);
    }

    /**
     * @throws JobFailedException
     */
    private function failOnLastAttempt(Document $document, JobContext $context): never
    {
        if ($context->isLastAttempt()) {
            ($this->failDocument)($document, UploadErrorCode::UNERWARTETER_FEHLER);

            throw JobFailedException::permanent(UploadErrorCode::UNERWARTETER_FEHLER);
        }

        throw JobFailedException::retryable(UploadErrorCode::UNERWARTETER_FEHLER);
    }
}
