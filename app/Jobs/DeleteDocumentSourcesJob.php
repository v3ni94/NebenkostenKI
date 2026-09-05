<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Documents\DeleteOriginalSources;
use App\Application\Documents\Dto\DeletionReason;
use App\Jobs\Concerns\ResolvesDocumentFromPayload;
use App\Models\Document;
use App\Models\ProcessingJob;
use App\Services\Queue\JobContext;
use App\Services\Queue\JobFailedException;
use App\Services\Queue\ProcessingJobHandler;
use App\Services\Storage\UploadErrorCode;

/**
 * Teiljob: Quelldaten loeschen.
 *
 * Dies ist der Wiederholungspfad, nicht der Regelweg. Im Regelfall wird
 * unmittelbar nach der Extraktion oder beim endgueltigen Fehler synchron
 * geloescht, damit die Loeschung nicht von einem Cron-Intervall abhaengt.
 * Dieser Teiljob greift, wenn die synchrone Loeschung fehlgeschlagen ist.
 *
 * Er ist idempotent: Ist bereits alles geloescht, meldet er Erfolg, ohne einen
 * weiteren Nachweis zu schreiben.
 */
final class DeleteDocumentSourcesJob implements ProcessingJobHandler
{
    use ResolvesDocumentFromPayload;

    public function __construct(private readonly DeleteOriginalSources $deleteSources) {}

    public function handle(ProcessingJob $job, JobContext $context): void
    {
        $document = $this->documentFrom($job);

        if (! $document instanceof Document) {
            return;
        }

        $outcome = ($this->deleteSources)($document, DeletionReason::WIEDERHOLUNG);

        if ($outcome->isSuccessful()) {
            return;
        }

        throw JobFailedException::retryable(
            UploadErrorCode::tryFrom($outcome->errorCode ?? '') ?? UploadErrorCode::LOESCHUNG_FEHLGESCHLAGEN
        );
    }
}
