<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Dto\DeletionReason;
use App\Application\Documents\Dto\ExtractionOutcome;
use App\Application\Documents\Support\AiPipelineResolver;
use App\Enums\DocumentProcessingStatus;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Use Case: Extraktion anstossen und danach SOFORT loeschen.
 *
 * Das ist die Stelle, an der Abschnitt 6.3 Schritt 15 und 16 durchgesetzt
 * werden:
 *
 * - Nach erfolgreicher, schemavalidierter Extraktion werden Providerdatei,
 *   lokale Originaldatei, Seitenbilder, Konvertierungen und der vollstaendige
 *   OCR-Text unmittelbar geloescht, nicht spaeter und nicht durch einen
 *   nachgelagerten Job.
 * - Bei endgueltigem Fehler wird ebenfalls sofort geloescht. Ein neuer Versuch
 *   erfordert einen erneuten Upload.
 * - Nur bei einem voruebergehenden Fehler bleiben die Quelldaten fuer den
 *   naechsten Versuch erhalten, laengstens bis zum Ablauf der Kurzzeit-TTL.
 *
 * Die Loeschung laeuft im selben Aufruf und nicht ueber die Queue, damit sie
 * nicht von einem Cron-Intervall abhaengt.
 */
final class StartExtraction
{
    public function __construct(
        private readonly AiPipelineResolver $pipeline,
        private readonly DeleteOriginalSources $deleteSources,
        private readonly FailDocument $failDocument,
    ) {}

    public function __invoke(Document $document): ExtractionOutcome
    {
        $upload = $this->requireUpload($document);

        $extractor = $this->pipeline->extractor();

        if ($extractor === null) {
            return ExtractionOutcome::failedTemporarily(UploadErrorCode::KI_SCHICHT_NICHT_VERFUEGBAR->value);
        }

        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::EXTRAKTION,
        ])->save();

        try {
            $outcome = $extractor->extract($document, $upload);
        } catch (Throwable) {
            // Ohne Meldung: eine Providerausnahme darf keine Inhalte tragen.
            $outcome = ExtractionOutcome::failedTemporarily(UploadErrorCode::EXTRAKTION_FEHLGESCHLAGEN->value);
        }

        if ($outcome->successful && $outcome->schemaValid) {
            $this->completeAndDelete($document, $outcome);

            return $outcome;
        }

        if ($outcome->permanent) {
            ($this->failDocument)(
                $document,
                UploadErrorCode::tryFrom($outcome->errorCode ?? '') ?? UploadErrorCode::EXTRAKTION_FEHLGESCHLAGEN,
            );
        }

        return $outcome;
    }

    /**
     * Erfolgreicher Abschluss: Metadaten festhalten und Quelldaten sofort
     * loeschen.
     */
    private function completeAndDelete(Document $document, ExtractionOutcome $outcome): void
    {
        $attributes = [
            'processing_status' => DocumentProcessingStatus::ABGESCHLOSSEN,
            'extracted_at' => Carbon::now(),
            'failure_code' => null,
            'failure_message' => null,
        ];

        if ($outcome->pageCount !== null) {
            $attributes['page_count'] = $outcome->pageCount;
        }

        $document->forceFill($attributes)->save();

        ($this->deleteSources)($document, DeletionReason::EXTRAKTION_ABGESCHLOSSEN);
    }

    /**
     * @throws UploadRejectedException
     */
    private function requireUpload(Document $document): TemporaryUpload
    {
        $upload = TemporaryUpload::query()
            ->where('document_id', $document->getKey())
            ->where('is_tombstone', false)
            ->first();

        if (! $upload instanceof TemporaryUpload) {
            throw UploadRejectedException::because(UploadErrorCode::QUELLE_NICHT_VORHANDEN);
        }

        return $upload;
    }
}
