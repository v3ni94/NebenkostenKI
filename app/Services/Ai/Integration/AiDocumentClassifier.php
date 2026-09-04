<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

use App\Application\Documents\Contracts\DocumentClassifier;
use App\Application\Documents\Dto\ClassificationOutcome;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Ai\AiDocumentProviderInterface;
use App\Services\Ai\Dto\AiRequestContext;
use App\Services\Ai\Dto\ClassificationResult;
use App\Services\Ai\Dto\ClassifyDocumentRequest;
use App\Services\Ai\Exceptions\CostBasisMissingException;
use App\Services\Ai\Exceptions\DailyCostLimitExceededException;
use App\Services\Ai\Exceptions\ProviderFileNotReleasedException;
use App\Services\Ai\Exceptions\ProviderNotReleasedException;
use App\Services\Ai\Exceptions\ProviderTransportException;
use App\Services\Ai\Exceptions\RateLimitException;
use App\Services\Ai\Exceptions\UnsupportedFileTypeException;
use App\Services\Ai\RedactingLogger;
use Throwable;

/**
 * Klassifikation eines Dokuments ueber die KI-Schicht
 * (Abschnitt 6.2 und 6.3 Schritt 7).
 *
 * Der Adapter liest die Quelldatei aus dem Kurzzeitbereich, ruft ueber den
 * AiProviderRouter die Klassifikation auf und uebernimmt ausschliesslich
 * Dokumenttyp und Konfidenz. Rohantwort und Volltext werden nach der
 * Validierung in der KI-Schicht verworfen und erreichen diesen Adapter nicht.
 *
 * ES WIRD NICHTS GERATEN: Liefert der Provider keinen zuordenbaren Typ, ist
 * die Antwort nicht schemakonform oder ist die Konfidenz nicht belastbar, wird
 * "undetermined" mit Fehlercode zurueckgegeben. Das Dokument bleibt SONSTIGES,
 * die Verarbeitung laeuft weiter und der Nutzer ordnet die Unterlage manuell zu
 * (Grundsatz 5).
 *
 * ES WIRD NICHTS BEFOLGT: Ein im Dokument enthaltener Anweisungstext wird von
 * der KI-Schicht ausschliesslich als Merkmal gemeldet. Der Adapter
 * protokolliert das als Sicherheitshinweis und behandelt das Dokument
 * unveraendert als untrusted data (Abschnitt 13.6).
 */
final class AiDocumentClassifier implements DocumentClassifier
{
    public function __construct(
        private readonly AiDocumentProviderInterface $router,
        private readonly DocumentPayloadFactory $payloads,
        private readonly DailyCostLedger $ledger,
        private readonly AiCallRecorder $calls,
        private readonly RedactingLogger $logger,
        private readonly OpenProviderFileGuard $providerFiles,
    ) {}

    public function classify(Document $document, TemporaryUpload $upload): ClassificationOutcome
    {
        $payload = $this->payloads->forUpload($document, $upload);

        if ($payload === null) {
            return ClassificationOutcome::failed(
                AiIntegrationErrorCode::QUELLE_NICHT_LESBAR->outcomeCode()
            );
        }

        // Eine offene Providerdatei eines frueheren Aufrufs wird zuerst erneut
        // geloescht; scheitert das, wartet der Aufruf auf die Wiederholung.
        if (! $this->providerFiles->release($document, $upload)) {
            return ClassificationOutcome::failed(AiIntegrationErrorCode::PROVIDER_LOESCHUNG_OFFEN->outcomeCode());
        }

        $spentMilliCent = $this->ledger->spentMilliCentToday($document);
        $observer = new DocumentAiCallObserver($document, $upload, $this->calls, $this->logger);

        try {
            $this->ledger->assertBudgetAvailable($spentMilliCent);

            $result = $this->router->classifyDocument(new ClassifyDocumentRequest(
                $payload,
                new AiRequestContext(
                    (string) $document->getKey(),
                    $this->ledger->userReference($document),
                    $spentMilliCent,
                    null,
                    // Solange eine fruehere Providerdatei nicht bestaetigt
                    // geloescht ist, wird keine weitere angelegt.
                    ! DocumentAiCallObserver::hasUnresolvedProviderFile($upload),
                    $observer,
                ),
            ));
        } catch (Throwable $exception) {
            return ClassificationOutcome::failed($this->failureFor($exception)->outcomeCode());
        }

        $observer->noteDeletionOutcomes($result->extraction->providerFileDeletions);
        $this->calls->recordPreceding($document, $result->extraction->precedingCalls);
        $this->calls->record($document, $result->metadata(), 1);

        if ($result->containsInstructionLikeText) {
            // Sicherheitsprotokoll ohne Inhalt. Die Anweisung wurde nicht
            // befolgt, Dokumentinhalte sind ausschliesslich untrusted data.
            $this->logger->warning('Dokument enthaelt anweisungsaehnlichen Text', [
                'document_label' => $document->getAttribute('source_label'),
                'correlation_id' => (string) $document->getKey(),
                'purpose' => $result->metadata()->purpose->value,
            ]);
        }

        return $this->toOutcome($result);
    }

    private function toOutcome(ClassificationResult $result): ClassificationOutcome
    {
        if (! $result->isValidated()) {
            return ClassificationOutcome::undetermined(
                AiIntegrationErrorCode::SCHEMA_UNGUELTIG->outcomeCode()
            );
        }

        $type = $result->documentType;

        if (! $type instanceof DocumentType || $type === DocumentType::SONSTIGES) {
            return ClassificationOutcome::undetermined(
                AiIntegrationErrorCode::KEIN_SCHEMA_FUER_DOKUMENTART->outcomeCode()
            );
        }

        return ClassificationOutcome::classified($type, $result->confidence);
    }

    /**
     * Abbruchgrund ohne Uebernahme der Ausnahmemeldung. Providermeldungen
     * koennten Dokumentinhalte tragen und werden deshalb verworfen.
     */
    private function failureFor(Throwable $exception): AiIntegrationErrorCode
    {
        return match (true) {
            $exception instanceof DailyCostLimitExceededException => AiIntegrationErrorCode::TAGESLIMIT_ERREICHT,
            $exception instanceof ProviderNotReleasedException => AiIntegrationErrorCode::PROVIDER_NICHT_FREIGEGEBEN,
            $exception instanceof RateLimitException => AiIntegrationErrorCode::PROVIDER_RATE_LIMIT,
            $exception instanceof UnsupportedFileTypeException => AiIntegrationErrorCode::DATEITYP_NICHT_UNTERSTUETZT,
            $exception instanceof ProviderFileNotReleasedException => AiIntegrationErrorCode::PROVIDER_LOESCHUNG_OFFEN,
            $exception instanceof CostBasisMissingException => AiIntegrationErrorCode::KALKULATIONSBASIS_FEHLT,
            $exception instanceof ProviderTransportException => AiIntegrationErrorCode::PROVIDER_NICHT_ERREICHBAR,
            default => AiIntegrationErrorCode::UNERWARTETER_FEHLER,
        };
    }
}
