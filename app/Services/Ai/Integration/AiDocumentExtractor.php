<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

use App\Application\Documents\Contracts\DocumentExtractor;
use App\Application\Documents\Dto\ExtractionOutcome;
use App\Enums\AiCallPurpose;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Ai\AiDocumentProviderInterface;
use App\Services\Ai\Dto\AiRequestContext;
use App\Services\Ai\Dto\AnalyzeContractRequest;
use App\Services\Ai\Dto\AnalyzePriorStatementRequest;
use App\Services\Ai\Dto\DocumentPayload;
use App\Services\Ai\Dto\ExtractionResult;
use App\Services\Ai\Dto\ExtractStructuredDataRequest;
use App\Services\Ai\Exceptions\DailyCostLimitExceededException;
use App\Services\Ai\Exceptions\ProviderNotReleasedException;
use App\Services\Ai\Exceptions\ProviderTransportException;
use App\Services\Ai\Exceptions\RateLimitException;
use App\Services\Ai\Exceptions\UnsupportedFileTypeException;
use App\Services\Ai\RedactingLogger;
use App\Services\Ai\Schemas\SchemaRegistry;
use Throwable;

/**
 * Strukturierte Extraktion ueber die KI-Schicht mit anschliessender Persistenz
 * (Abschnitt 6.3 Schritte 8 bis 10).
 *
 * ABLAUF:
 *   1. Quelldatei aus dem Kurzzeitbereich lesen
 *   2. Schema anhand der Dokumentart bestimmen
 *   3. Tagesbudget aus ai_calls summieren und pruefen
 *   4. Aufruf ueber den AiProviderRouter, der Freigabesperre, Fallback und
 *      die zulaessigen Reparaturversuche durchsetzt
 *   5. Nachweis in ai_calls und ai_prompt_versions schreiben
 *   6. ausschliesslich strukturierte Felder, Seiten und minimale
 *      Fundstellenausschnitte persistieren
 *
 * DIE RUECKGABE ENTSCHEIDET UEBER DEN LOESCHZEITPUNKT. StartExtraction loescht
 * die Quelldaten bei Erfolg und bei endgueltigem Fehler sofort. Deshalb ist
 * jede Rueckgabe bewusst gewaehlt:
 *
 *   - completed: Extraktion schemavalidiert, Felder persistiert
 *   - failedPermanently: fachlich aussichtslos, ein erneuter Versuch mit
 *     denselben Daten wuerde dasselbe Ergebnis liefern. Dazu zaehlen eine
 *     Schemaverletzung nach allen Reparaturversuchen, ein nicht auswertbarer
 *     Dateityp, eine fehlende Schemazuordnung und das ausgeschoepfte
 *     Tagesbudget. Der Nutzer erfasst die Werte manuell.
 *   - failedTemporarily: betrieblicher Fehler, der sich von selbst erledigen
 *     kann, also Ratenbegrenzung, technischer Fehler und die noch fehlende
 *     Datenschutzfreigabe. Der Teiljob wird mit Backoff wiederholt und
 *     loescht die Quelldaten spaetestens nach dem letzten Versuch.
 *
 * Es wird niemals eine Ausnahme mit Providerinhalt nach aussen gegeben.
 */
final class AiDocumentExtractor implements DocumentExtractor
{
    public function __construct(
        private readonly AiDocumentProviderInterface $router,
        private readonly DocumentPayloadFactory $payloads,
        private readonly DocumentSchemaMap $schemaMap,
        private readonly SchemaRegistry $schemas,
        private readonly DailyCostLedger $ledger,
        private readonly AiCallRecorder $calls,
        private readonly ExtractedFieldPersister $persister,
        private readonly RedactingLogger $logger,
    ) {}

    public function extract(Document $document, TemporaryUpload $upload): ExtractionOutcome
    {
        $type = $document->getAttribute('document_type');

        if (! $type instanceof DocumentType) {
            return $this->fail($document, AiIntegrationErrorCode::KEIN_SCHEMA_FUER_DOKUMENTART);
        }

        $schemaKey = $this->schemaMap->schemaKeyFor($type);

        if ($schemaKey === null) {
            return $this->fail($document, AiIntegrationErrorCode::KEIN_SCHEMA_FUER_DOKUMENTART);
        }

        $payload = $this->payloads->forUpload($document, $upload);

        if ($payload === null) {
            return $this->fail($document, AiIntegrationErrorCode::QUELLE_NICHT_LESBAR);
        }

        $spentMilliCent = $this->ledger->spentMilliCentToday($document);
        $observer = new DocumentAiCallObserver($document, $upload, $this->calls, $this->logger);

        try {
            $this->ledger->assertBudgetAvailable($spentMilliCent);

            $result = $this->callProvider($document, $upload, $payload, $type, $schemaKey, $spentMilliCent, $observer);
        } catch (Throwable $exception) {
            return $this->fail($document, $this->failureFor($exception));
        }

        // Loeschstatus der Providerdateien und Verbrauch verworfener
        // Primaeraufrufe gehoeren zum Nachweis, auch wenn das fachliche
        // Ergebnis gueltig ist.
        $observer->noteDeletionOutcomes($result->providerFileDeletions);
        $this->calls->recordPreceding($document, $result->precedingCalls);

        $aiCall = $this->calls->record(
            $document,
            $result->metadata,
            1,
            $result->isValidated() || $result->hasConflict() ? null : AiIntegrationErrorCode::SCHEMA_UNGUELTIG,
        );

        if ($result->requiresManualEntry()) {
            // Schemaverletzung nach allen zulaessigen Reparaturversuchen. Es
            // wird nichts persistiert, damit kein halb geprueftes Ergebnis in
            // die Abrechnung gelangt. Die Quelldaten werden von der Pipeline
            // sofort geloescht, der Nutzer erfasst die Werte manuell.
            $this->logger->warning('Extraktion nach Reparaturversuchen nicht schemakonform', [
                'schema_key' => $schemaKey,
                'violation_count' => count($result->violations),
                'violation_codes' => array_map(
                    static fn ($violation): string => $violation->code->value,
                    $result->violations,
                ),
                'attempts' => $result->metadata->attempts,
                'correlation_id' => (string) $document->getKey(),
            ]);

            return $this->fail($document, AiIntegrationErrorCode::SCHEMA_UNGUELTIG);
        }

        $summary = $this->persister->persist(
            $document,
            $result,
            $schemaKey,
            $this->schemas->version($schemaKey),
            $aiCall,
        );

        $this->logger->info('Strukturierte Extraktionsdaten persistiert', [
            'schema_key' => $schemaKey,
            'schema_version' => $this->schemas->version($schemaKey),
            'review_required_count' => $summary->reviewRequiredCount,
            'missing_value_count' => $summary->missingValueCount,
            'document_page_count' => $summary->pageCount,
            'correlation_id' => (string) $document->getKey(),
        ]);

        return ExtractionOutcome::completed($summary->persistedFieldCount, $summary->pageCount);
    }

    private function callProvider(
        Document $document,
        TemporaryUpload $upload,
        DocumentPayload $payload,
        DocumentType $type,
        string $schemaKey,
        int $spentMilliCent,
        DocumentAiCallObserver $observer,
    ): ExtractionResult {
        $context = new AiRequestContext(
            (string) $document->getKey(),
            $this->ledger->userReference($document),
            $spentMilliCent,
            null,
            // Solange eine fruehere Providerdatei nicht bestaetigt geloescht
            // ist, wird keine weitere angelegt (siehe DocumentAiCallObserver).
            ! DocumentAiCallObserver::hasUnresolvedProviderFile($upload),
            $observer,
        );

        return match ($this->schemaMap->purposeFor($type)) {
            AiCallPurpose::VERTRAGSANALYSE => $this->router->analyzeContract(
                new AnalyzeContractRequest($payload, $context, $this->schemaMap->isAmendment($type)),
            ),
            AiCallPurpose::VORJAHRESANALYSE => $this->router->analyzePriorStatement(
                new AnalyzePriorStatementRequest($payload, $context),
            ),
            default => $this->router->extractStructuredData(
                new ExtractStructuredDataRequest($payload, $schemaKey, $context, $type),
            ),
        };
    }

    private function fail(Document $document, AiIntegrationErrorCode $code): ExtractionOutcome
    {
        $this->logger->warning('Extraktion abgebrochen', [
            'error_code' => $code->value,
            'correlation_id' => (string) $document->getKey(),
            'document_label' => $document->getAttribute('source_label'),
        ]);

        return $code->isPermanent()
            ? ExtractionOutcome::failedPermanently($code->outcomeCode())
            : ExtractionOutcome::failedTemporarily($code->outcomeCode());
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
            $exception instanceof ProviderTransportException => AiIntegrationErrorCode::PROVIDER_NICHT_ERREICHBAR,
            default => AiIntegrationErrorCode::UNERWARTETER_FEHLER,
        };
    }
}
