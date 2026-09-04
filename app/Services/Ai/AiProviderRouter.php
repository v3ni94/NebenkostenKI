<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Dto\AnalyzeContractRequest;
use App\Services\Ai\Dto\AnalyzePriorStatementRequest;
use App\Services\Ai\Dto\ClassificationResult;
use App\Services\Ai\Dto\ClassifyDocumentRequest;
use App\Services\Ai\Dto\ExtractionResult;
use App\Services\Ai\Dto\ExtractStructuredDataRequest;
use App\Services\Ai\Dto\HealthCheckRequest;
use App\Services\Ai\Dto\HealthCheckResult;
use App\Services\Ai\Dto\ReconcileDocumentsRequest;
use App\Services\Ai\Dto\ReconciliationResult;
use App\Services\Ai\Exceptions\ProviderNotReleasedException;
use App\Services\Ai\Exceptions\ProviderTransportException;
use App\Services\Ai\Exceptions\RateLimitException;
use App\Services\Ai\Exceptions\UnsupportedFileTypeException;
use InvalidArgumentException;

/**
 * Routing und Fallback der KI-Schicht nach Abschnitt 13.5.
 *
 * VERBINDLICHE REGELN:
 *
 * 1. DATENMINIMIERUNG UND KOSTENKONTROLLE: Standardmaessig wird ein Dokument
 *    nur an EINEN Provider gesendet.
 * 2. FALLBACK NUR TECHNISCH: Ein Fallback ist ausschliesslich zulaessig bei
 *    technischem Fehler, Ratenbegrenzung, nicht unterstuetztem Dateityp oder
 *    wiederholter Schemaverletzung, und nur wenn ai.fallback_enabled aktiv ist.
 *    Ein fachlich unplausibles, aber schemakonformes Ergebnis loest KEINEN
 *    Fallback aus. Fachliche Plausibilitaet prueft die deterministische
 *    Regel-Engine, nicht ein zweiter Provider.
 * 3. FREIGABESPERRE IST NICHT UMGEHBAR: Vor jedem echten Provideraufruf greift
 *    ProviderReleaseGate. Das gilt fuer den Primaerprovider UND fuer den
 *    Fallbackprovider. Ist der Fallbackprovider gesperrt, wird der Fallback
 *    nicht ausgefuehrt und der Fehler des Primaerprovders bleibt bestehen.
 * 4. DUAL REVIEW OHNE MEHRHEITSENTSCHEID: Ist ai.dual_review_enabled aktiv,
 *    werden beide Provider befragt. Widersprechen sich die Ergebnisse fachlich,
 *    wird KEIN Mehrheitsentscheid getroffen. Das Ergebnis erhaelt Status
 *    KONFLIKT und einen Konfliktbericht fuer den Nutzer.
 * 5. NUR METADATEN IM LOG: Es werden ausschliesslich Metadaten protokolliert.
 */
final class AiProviderRouter implements AiDocumentProviderInterface
{
    public const FALLBACK_REASON_TRANSPORT = 'technischer_fehler';

    public const FALLBACK_REASON_RATE_LIMIT = 'rate_limit';

    public const FALLBACK_REASON_UNSUPPORTED_FILE = 'nicht_unterstuetzter_dateityp';

    public const FALLBACK_REASON_SCHEMA = 'wiederholte_schemaverletzung';

    /**
     * @param  array<string, AiDocumentProviderInterface>  $providers  Providerschluessel auf Implementierung.
     */
    public function __construct(
        private readonly AiConfig $config,
        private readonly ProviderReleaseGate $releaseGate,
        private readonly DualReviewComparator $comparator,
        private readonly RedactingLogger $logger,
        private readonly array $providers,
    ) {}

    public function providerKey(): string
    {
        return $this->config->primaryProvider->value;
    }

    public function supportsMimeType(string $mimeType): bool
    {
        foreach ($this->availableProviderKeys() as $key) {
            if ($this->provider($key)->supportsMimeType($mimeType)) {
                return true;
            }
        }

        return false;
    }

    public function classifyDocument(ClassifyDocumentRequest $request): ClassificationResult
    {
        return $this->dispatchClassification(
            static fn (AiDocumentProviderInterface $provider): ClassificationResult => $provider->classifyDocument($request),
        );
    }

    public function extractStructuredData(ExtractStructuredDataRequest $request): ExtractionResult
    {
        return $this->dispatchExtraction(
            static fn (AiDocumentProviderInterface $provider): ExtractionResult => $provider->extractStructuredData($request),
        );
    }

    public function analyzeContract(AnalyzeContractRequest $request): ExtractionResult
    {
        return $this->dispatchExtraction(
            static fn (AiDocumentProviderInterface $provider): ExtractionResult => $provider->analyzeContract($request),
        );
    }

    public function analyzePriorStatement(AnalyzePriorStatementRequest $request): ExtractionResult
    {
        return $this->dispatchExtraction(
            static fn (AiDocumentProviderInterface $provider): ExtractionResult => $provider->analyzePriorStatement($request),
        );
    }

    public function reconcileDocuments(ReconcileDocumentsRequest $request): ReconciliationResult
    {
        $result = $this->dispatchExtraction(
            static fn (AiDocumentProviderInterface $provider): ExtractionResult => $provider->reconcileDocuments($request)->extraction,
        );

        return new ReconciliationResult($result);
    }

    /**
     * Healthcheck des Primaerprovders, ergaenzt um den Freigabestatus.
     *
     * Der Healthcheck laeuft bewusst OHNE Freigabesperre, weil er keine
     * Dokumentinhalte sendet und der Adminbereich gerade den Freigabestatus
     * sichtbar machen soll.
     */
    public function healthCheck(HealthCheckRequest $request): HealthCheckResult
    {
        return $this->healthCheckFor($this->config->primaryProvider, $request);
    }

    /**
     * Healthcheck aller konfigurierten Provider fuer den Adminbereich.
     *
     * @return array<string, HealthCheckResult>
     */
    public function healthCheckAll(HealthCheckRequest $request): array
    {
        $results = [];

        foreach ($this->availableProviderKeys() as $key) {
            $results[$key->value] = $this->healthCheckFor($key, $request);
        }

        return $results;
    }

    public function primaryProviderKey(): AiProviderKey
    {
        return $this->config->primaryProvider;
    }

    public function fallbackProviderKey(): ?AiProviderKey
    {
        return $this->config->fallbackProvider;
    }

    // -----------------------------------------------------------------
    // Ablauf
    // -----------------------------------------------------------------

    /**
     * @param  callable(AiDocumentProviderInterface): ExtractionResult  $call
     */
    private function dispatchExtraction(callable $call): ExtractionResult
    {
        if ($this->isDualReviewActive()) {
            return $this->dispatchDualReview($call);
        }

        return $this->dispatchSingle($call);
    }

    /**
     * @param  callable(AiDocumentProviderInterface): ClassificationResult  $call
     */
    private function dispatchClassification(callable $call): ClassificationResult
    {
        $primaryKey = $this->config->primaryProvider;
        $this->releaseGate->assertReleased($primaryKey);

        try {
            return $call($this->provider($primaryKey));
        } catch (RateLimitException|ProviderTransportException|UnsupportedFileTypeException $exception) {
            $reason = self::fallbackReasonFor($exception);
            $fallbackKey = $this->resolveFallbackKey($primaryKey, $reason);

            if ($fallbackKey === null) {
                throw $exception;
            }

            $result = $call($this->provider($fallbackKey));

            return new ClassificationResult(
                $result->extraction->withMetadata(
                    $result->extraction->metadata->withFallback($primaryKey->value, $reason),
                ),
                $result->documentType,
                $result->confidence,
                $result->alternatives,
                $result->containsInstructionLikeText,
            );
        }
    }

    /**
     * Standardweg: genau ein Provider. Fallback nur bei technischem Anlass.
     *
     * @param  callable(AiDocumentProviderInterface): ExtractionResult  $call
     */
    private function dispatchSingle(callable $call): ExtractionResult
    {
        $primaryKey = $this->config->primaryProvider;
        $this->releaseGate->assertReleased($primaryKey);

        try {
            $result = $call($this->provider($primaryKey));
        } catch (RateLimitException|ProviderTransportException|UnsupportedFileTypeException $exception) {
            $reason = self::fallbackReasonFor($exception);
            $fallbackKey = $this->resolveFallbackKey($primaryKey, $reason);

            if ($fallbackKey === null) {
                throw $exception;
            }

            return $this->callWithFallbackMetadata($call, $primaryKey, $fallbackKey, $reason);
        }

        // Ein schemakonformes Ergebnis wird NIEMALS an einen zweiten Provider
        // weitergegeben, auch dann nicht, wenn es fachlich unplausibel wirkt.
        if ($result->isValidated()) {
            return $result;
        }

        $fallbackKey = $this->resolveFallbackKey($primaryKey, self::FALLBACK_REASON_SCHEMA);

        if ($fallbackKey === null) {
            return $result;
        }

        // Das Ergebnis des Primaerproviders wird verworfen, sein Verbrauch
        // aber nicht: der Primaerprovider hat bis zu maxRetries + 1 Anfragen
        // mit Dokument gesendet. Die Metadaten wandern als vorangegangener
        // Aufruf mit, damit ai_calls, Tagesbudget und Kostenuebersicht
        // vollstaendig bleiben.
        return $this->callWithFallbackMetadata($call, $primaryKey, $fallbackKey, self::FALLBACK_REASON_SCHEMA)
            ->withPrecedingCall($result->metadata);
    }

    /**
     * Dual Review: beide Provider werden befragt, Widersprueche gehen als
     * Konflikt an den Aufrufer. Es gibt keinen Mehrheitsentscheid.
     *
     * @param  callable(AiDocumentProviderInterface): ExtractionResult  $call
     */
    private function dispatchDualReview(callable $call): ExtractionResult
    {
        $primaryKey = $this->config->primaryProvider;
        $secondaryKey = $this->config->fallbackProvider;

        $this->releaseGate->assertReleased($primaryKey);

        if ($secondaryKey === null || $secondaryKey === $primaryKey) {
            return $this->dispatchSingle($call);
        }

        // Auch im Dual-Review-Modus ist die Freigabesperre nicht umgehbar.
        $this->releaseGate->assertReleased($secondaryKey);

        $primaryResult = $call($this->provider($primaryKey));
        $secondaryResult = $call($this->provider($secondaryKey));

        $report = $this->comparator->compare($primaryResult, $secondaryResult);

        $this->logger->info('Dual Review ausgefuehrt', [
            'primary_provider' => $primaryKey->value,
            'fallback_provider' => $secondaryKey->value,
            'dual_review_used' => true,
            'conflict_count' => $report->count(),
            'conflict_paths' => $report->paths(),
            'correlation_id' => $primaryResult->metadata->correlationId,
        ]);

        if (! $report->hasConflicts()) {
            return $primaryResult->withMetadata($primaryResult->metadata->withDualReview());
        }

        return $primaryResult->withConflict($report);
    }

    /**
     * @param  callable(AiDocumentProviderInterface): ExtractionResult  $call
     */
    private function callWithFallbackMetadata(
        callable $call,
        AiProviderKey $primaryKey,
        AiProviderKey $fallbackKey,
        string $reason,
    ): ExtractionResult {
        $result = $call($this->provider($fallbackKey));

        return $result->withMetadata($result->metadata->withFallback($primaryKey->value, $reason));
    }

    /**
     * Ermittelt den zulaessigen Fallbackprovider oder null.
     *
     * Die Freigabesperre wird hier ausdruecklich geprueft. Ist der
     * Fallbackprovider nicht freigegeben, findet KEIN Fallback statt.
     */
    private function resolveFallbackKey(AiProviderKey $primaryKey, string $reason): ?AiProviderKey
    {
        if (! $this->config->fallbackEnabled) {
            return null;
        }

        $fallbackKey = $this->config->fallbackProvider;

        if ($fallbackKey === null || $fallbackKey === $primaryKey) {
            return null;
        }

        if (! isset($this->providers[$fallbackKey->value])) {
            return null;
        }

        if (! $this->releaseGate->isReleased($fallbackKey)) {
            $this->logger->warning('Fallback durch Freigabesperre blockiert', [
                'primary_provider' => $primaryKey->value,
                'fallback_provider' => $fallbackKey->value,
                'fallback_blocked' => true,
                'fallback_reason' => $reason,
            ]);

            return null;
        }

        $this->logger->warning('Fallback auf zweiten Provider', [
            'primary_provider' => $primaryKey->value,
            'fallback_provider' => $fallbackKey->value,
            'fallback_used' => true,
            'fallback_reason' => $reason,
        ]);

        return $fallbackKey;
    }

    private function isDualReviewActive(): bool
    {
        return $this->config->dualReviewEnabled
            && $this->config->fallbackProvider !== null
            && $this->config->fallbackProvider !== $this->config->primaryProvider
            && isset($this->providers[$this->config->fallbackProvider->value]);
    }

    private function healthCheckFor(AiProviderKey $key, HealthCheckRequest $request): HealthCheckResult
    {
        $result = $this->provider($key)->healthCheck($request);
        $released = $this->releaseGate->isReleased($key);
        $blockReason = $this->releaseGate->blockReason($key);

        return new HealthCheckResult(
            $result->providerKey,
            $result->model,
            $result->reachable,
            $result->modelAvailable,
            $released,
            $result->httpStatusCode,
            $result->durationMs,
            $result->correlationId,
            $released ? $result->message : $result->message.' Freigabe fehlt: '.((string) $blockReason),
            $result->apiKeyConfigured,
        );
    }

    private function provider(AiProviderKey $key): AiDocumentProviderInterface
    {
        $provider = $this->providers[$key->value] ?? null;

        if ($provider === null) {
            throw new InvalidArgumentException(sprintf(
                'Fuer den Provider "%s" ist keine Implementierung registriert.',
                $key->value,
            ));
        }

        return $provider;
    }

    /**
     * @return list<AiProviderKey>
     */
    private function availableProviderKeys(): array
    {
        $keys = [];

        foreach (array_keys($this->providers) as $key) {
            $providerKey = AiProviderKey::tryFromKey((string) $key);

            if ($providerKey !== null) {
                $keys[] = $providerKey;
            }
        }

        return $keys;
    }

    private static function fallbackReasonFor(
        RateLimitException|ProviderTransportException|UnsupportedFileTypeException|ProviderNotReleasedException $exception,
    ): string {
        return match (true) {
            $exception instanceof RateLimitException => self::FALLBACK_REASON_RATE_LIMIT,
            $exception instanceof UnsupportedFileTypeException => self::FALLBACK_REASON_UNSUPPORTED_FILE,
            default => self::FALLBACK_REASON_TRANSPORT,
        };
    }
}
