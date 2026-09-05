<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Dto\DeletionOutcome;
use App\Application\Documents\Dto\DeletionReason;
use App\Enums\DocumentProcessingStatus;
use App\Models\Document;
use App\Services\Storage\UploadErrorCode;

/**
 * Use Case: ein Dokument endgueltig scheitern lassen.
 *
 * VERBINDLICH (Abschnitt 6.3 Schritt 16): Bei einem endgueltigen Fehler werden
 * die Quelldaten SOFORT geloescht, genau wie nach einer erfolgreichen
 * Extraktion. Fuer einen neuen Versuch muss der Nutzer die Datei erneut
 * hochladen. Es gibt bewusst keinen Weg, eine fehlgeschlagene Datei zur
 * spaeteren Fehlersuche aufzubewahren.
 *
 * Gespeichert bleiben nur Fehlercode und verstaendliche deutsche Meldung, ohne
 * jeden Dateiinhalt. Der Fehlercode bleibt der fachliche Code zur
 * Nachverfolgung; die Meldung beschreibt den Endzustand und verspricht keine
 * automatische Wiederholung mehr, weil die Quelldatei geloescht ist.
 */
final class FailDocument
{
    public function __construct(private readonly DeleteOriginalSources $deleteSources) {}

    public function __invoke(
        Document $document,
        UploadErrorCode $errorCode,
        DocumentProcessingStatus $status = DocumentProcessingStatus::FEHLGESCHLAGEN,
    ): DeletionOutcome {
        $document->forceFill([
            'processing_status' => $status,
            'failure_code' => $errorCode->value,
            'failure_message' => mb_substr($errorCode->finalMessage(), 0, 500),
        ])->save();

        return ($this->deleteSources)($document, DeletionReason::ENDGUELTIGER_FEHLER);
    }
}
