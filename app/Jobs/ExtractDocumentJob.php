<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Documents\FailDocument;
use App\Application\Documents\StartExtraction;
use App\Enums\DocumentProcessingStatus;
use App\Jobs\Concerns\ResolvesDocumentFromPayload;
use App\Models\Document;
use App\Models\ProcessingJob;
use App\Services\Queue\JobContext;
use App\Services\Queue\JobFailedException;
use App\Services\Queue\ProcessingJobHandler;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\UploadErrorCode;

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

        try {
            $outcome = ($this->startExtraction)($document);
        } catch (UploadRejectedException $exception) {
            ($this->failDocument)($document, $exception->errorCode);

            throw JobFailedException::permanent($exception->errorCode);
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
}
