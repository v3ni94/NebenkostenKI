<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Documents\AssembleUpload;
use App\Application\Documents\DeleteOriginalSources;
use App\Application\Documents\Dto\DeletionReason;
use App\Application\Documents\FailDocument;
use App\Enums\DocumentProcessingStatus;
use App\Jobs\Concerns\ResolvesDocumentFromPayload;
use App\Models\Document;
use App\Models\ProcessingJob;
use App\Services\Queue\JobContext;
use App\Services\Queue\JobFailedException;
use App\Services\Queue\ProcessingJobHandler;
use App\Services\Storage\Crypto\CipherIntegrityException;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Teiljob: Dateiabschnitte zusammensetzen und die Pruefkette durchlaufen.
 *
 * IDEMPOTENZ: Ein bereits abgeschlossenes oder abgelehntes Dokument wird
 * uebersprungen. Eine bereits zusammengesetzte Datei wird nicht erneut
 * aufgebaut und das Laufvolumen nicht doppelt verbucht.
 *
 * ENDGUELTIGER FEHLER: Eine abgelehnte Datei wird sofort geloescht. Fuer einen
 * neuen Versuch muss der Nutzer erneut hochladen (Abschnitt 6.3 Schritt 16).
 */
final class AssembleUploadJob implements ProcessingJobHandler
{
    use ResolvesDocumentFromPayload;

    public function __construct(
        private readonly AssembleUpload $assembleUpload,
        private readonly FailDocument $failDocument,
        private readonly DeleteOriginalSources $deleteSources,
        private readonly DocumentPipeline $pipeline,
    ) {}

    public function handle(ProcessingJob $job, JobContext $context): void
    {
        $document = $this->documentFrom($job);

        if (! $document instanceof Document) {
            // Das Dokument wurde inzwischen entfernt. Nichts zu tun.
            return;
        }

        $status = $document->getAttribute('processing_status');

        if ($status instanceof DocumentProcessingStatus && $status->requiresSourceDeletion()) {
            return;
        }

        $extension = $this->stringFromPayload($job, 'erweiterung', 'pdf');

        try {
            $result = ($this->assembleUpload)($document, $extension);
        } catch (UploadRejectedException $exception) {
            $this->rejectOrRetry($document, $exception, $context);
        } catch (CipherIntegrityException) {
            // Ein Abschnitt ist nicht entschluesselbar (Manipulation, verlorener
            // Dateischluessel, gewechselter APP_KEY). Eine Wiederholung aendert
            // daran nichts: endgueltiger Fehler mit sofortiger Loeschung.
            ($this->failDocument)($document, UploadErrorCode::QUELLE_NICHT_LESBAR);

            throw JobFailedException::permanent(UploadErrorCode::QUELLE_NICHT_LESBAR);
        } catch (Throwable) {
            // Unbekannter technischer Fehler, ohne Meldung weitergereicht. Nach
            // dem letzten Versuch wird das Dokument gekennzeichnet und geloescht,
            // statt bis zur TTL im Kurzzeitbereich zu liegen.
            if ($context->isLastAttempt()) {
                ($this->failDocument)($document, UploadErrorCode::UNERWARTETER_FEHLER);

                throw JobFailedException::permanent(UploadErrorCode::UNERWARTETER_FEHLER);
            }

            throw JobFailedException::retryable(UploadErrorCode::UNERWARTETER_FEHLER);
        }

        if ($result->duplicate) {
            $this->completeAsDuplicate($document);

            return;
        }

        if ($result->archiveExpanded) {
            $this->completeArchive($document, $result->archiveDocuments);

            return;
        }

        $this->pipeline->queueClassification($document);
    }

    /**
     * @throws JobFailedException
     */
    private function rejectOrRetry(Document $document, UploadRejectedException $exception, JobContext $context): never
    {
        if ($exception->isPermanent() || $context->isLastAttempt()) {
            ($this->failDocument)($document, $exception->errorCode, DocumentProcessingStatus::ABGELEHNT);

            throw JobFailedException::permanent($exception->errorCode);
        }

        throw JobFailedException::retryable($exception->errorCode);
    }

    /**
     * Eine Dublette wird nicht erneut ausgewertet. Sie wird als solche
     * gekennzeichnet und ihre Quelldatei sofort geloescht, damit keine zweite
     * Kopie im Kurzzeitbereich liegt.
     */
    private function completeAsDuplicate(Document $document): void
    {
        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::ABGELEHNT,
            'failure_code' => UploadErrorCode::DUBLETTE->value,
            'failure_message' => mb_substr(UploadErrorCode::DUBLETTE->message(), 0, 500),
        ])->save();

        ($this->deleteSources)($document, DeletionReason::ENDGUELTIGER_FEHLER);
    }

    /**
     * Ein Archiv ist ein Container. Nach der Aufloesung in Einzeldokumente
     * wird die Archivdatei selbst nicht mehr benoetigt und sofort geloescht.
     *
     * @param  list<Document>  $expanded
     */
    private function completeArchive(Document $document, array $expanded): void
    {
        foreach ($expanded as $child) {
            $this->pipeline->queueClassification($child);
        }

        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::ABGESCHLOSSEN,
            'extracted_at' => Carbon::now(),
            'page_count' => null,
        ])->save();

        ($this->deleteSources)($document, DeletionReason::EXTRAKTION_ABGESCHLOSSEN);
    }
}
