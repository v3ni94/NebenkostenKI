<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Dto\ClassificationOutcome;
use App\Application\Documents\Support\AiPipelineResolver;
use App\Application\Documents\Support\SourceLabelFactory;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Use Case: Dokument klassifizieren lassen (Abschnitt 6.3 Schritt 7).
 *
 * Der Use Case stoesst die Klassifikation an und uebernimmt das Ergebnis in die
 * Metadaten. Er fuehrt selbst keine Providerlogik, kein Prompting und keine
 * Schemapruefung; das ist Aufgabe der KI-Schicht hinter DocumentClassifier.
 *
 * WICHTIG: Mit der Dokumentart aendert sich auch die neutrale
 * Quellenbezeichnung, zum Beispiel von "Dokument 01 - Nicht klassifiziert" zu
 * "Dokument 01 - Grundsteuerbescheid". Die laufende Nummer bleibt stabil,
 * damit bereits erzeugte Quellenverweise gueltig bleiben.
 *
 * Ein manuell gesetzter Dokumenttyp wird nicht ueberschrieben: die Zuordnung
 * des Nutzers hat Vorrang vor der Erkennung.
 */
final class ClassifyDocument
{
    public function __construct(
        private readonly AiPipelineResolver $pipeline,
        private readonly SourceLabelFactory $labels,
    ) {}

    public function __invoke(Document $document): ClassificationOutcome
    {
        $upload = $this->requireUpload($document);

        $classifier = $this->pipeline->classifier();

        if ($classifier === null) {
            return ClassificationOutcome::failed(UploadErrorCode::KI_SCHICHT_NICHT_VERFUEGBAR->value);
        }

        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::KLASSIFIZIERUNG,
        ])->save();

        try {
            $outcome = $classifier->classify($document, $upload);
        } catch (Throwable) {
            // Meldung und Stacktrace werden bewusst verworfen. Sie koennten
            // Dokumentinhalte oder Providerantworten enthalten.
            return ClassificationOutcome::failed(UploadErrorCode::KLASSIFIKATION_FEHLGESCHLAGEN->value);
        }

        $this->applyOutcome($document, $outcome);

        return $outcome;
    }

    private function applyOutcome(Document $document, ClassificationOutcome $outcome): void
    {
        $type = $outcome->documentType;

        if (! $type instanceof DocumentType) {
            return;
        }

        $manual = $document->getAttribute('type_assigned_manually') === true;

        $attributes = [
            'classified_at' => Carbon::now(),
            'processing_status' => DocumentProcessingStatus::EXTRAKTION,
        ];

        if (! $manual) {
            $attributes['document_type'] = $type;
            $attributes['document_type_confidence'] = $outcome->confidence;
            $attributes['source_label'] = $this->labels->truncate(
                $this->labels->forType((int) $document->getAttribute('sequence_number'), $type)
            );
        }

        $document->forceFill($attributes)->save();
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
            // Die Quelldatei ist bereits geloescht. Eine erneute Auswertung
            // ist ausgeschlossen, der Nutzer muss neu hochladen.
            throw UploadRejectedException::because(UploadErrorCode::QUELLE_NICHT_VORHANDEN);
        }

        return $upload;
    }
}
