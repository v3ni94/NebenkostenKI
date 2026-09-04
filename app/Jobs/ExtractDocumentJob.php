<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Documents\FailDocument;
use App\Application\Documents\StartExtraction;
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
 * Teiljob: Werte auslesen und die Quelldaten anschliessend sofort loeschen.
 *
 * Die Loeschung erfolgt bereits im Use Case StartExtraction, unmittelbar nach
 * der schemavalidierten Extraktion und ebenso bei endgueltigem Fehler. Dieser
 * Teiljob sorgt nur dafuer, dass der letzte Versuch nicht ohne Loeschung
 * endet.
 */
final class ExtractDocumentJob implements ProcessingJobHandler
{
    use ResolvesDocumentFromPayload;

    public function __construct(
        private readonly StartExtraction $startExtraction,
        private readonly FailDocument $failDocument,
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

        // Die KI-Anbindung verlaengert ueber diesen Heartbeat das Lease vor
        // jedem einzelnen Providerrequest, damit ein langer Aufruf nicht von
        // einem zweiten Lauf uebernommen und das Dokument doppelt uebertragen
        // wird.
        ActiveJobHeartbeat::bind(static fn (): bool => $context->heartbeat());

        try {
            $outcome = ($this->startExtraction)($document);
        } catch (UploadRejectedException $exception) {
            ($this->failDocument)($document, $exception->errorCode);

            throw JobFailedException::permanent($exception->errorCode);
        } catch (JobFailedException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Unbekannter technischer Fehler, ohne Meldung weitergereicht. Nach
            // dem letzten Versuch wird das Dokument gekennzeichnet und
            // geloescht, statt in EXTRAKTION stehen zu bleiben.
            $this->failOnLastAttempt($document, $context);
        } finally {
            ActiveJobHeartbeat::release();
        }

        if ($outcome->successful && $outcome->schemaValid) {
            return;
        }

        $errorCode = UploadErrorCode::tryFrom($outcome->errorCode ?? '')
            ?? UploadErrorCode::EXTRAKTION_FEHLGESCHLAGEN;

        if ($outcome->permanent) {
            // StartExtraction hat bereits gekennzeichnet und geloescht.
            throw JobFailedException::permanent($errorCode);
        }

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
