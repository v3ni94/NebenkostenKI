<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

use App\Enums\AiCallPurpose;
use App\Enums\AiCallStatus;

/**
 * Metadaten eines KI-Aufrufs.
 *
 * Nur diese Angaben sind fuer Protokolle, Datenbank und Error Monitoring
 * freigegeben (Abschnitt 6.4 und 13.5). Anfrage- und Antwortbodies gehoeren
 * nicht dazu und sind hier bewusst nicht vorgesehen.
 *
 * Die Kostenschaetzung ist eine dokumentierte Annahme zum Projektstand auf
 * Basis von ai.cost_basis_us_cent_per_million_tokens. Sie ist keine
 * Abrechnungsgrundlage und regelmaessig gegen die offizielle Preisliste des
 * Providers zu pruefen.
 */
final class AiCallMetadata
{
    /**
     * @param  string  $providerKey  Providerschluessel, zum Beispiel openai, anthropic oder fake.
     * @param  int|null  $estimatedCostCent  Geschaetzte Kosten in ganzen Cent, aufgerundet.
     * @param  int|null  $estimatedCostMilliCent  Geschaetzte Kosten in Tausendstel-Cent, fuer das Tagesbudget.
     * @param  bool  $costBasisAvailable  false, wenn fuer das Modell keine Kalkulationsbasis konfiguriert ist.
     * @param  list<string>  $providerRequestIds  Request-IDs aller Versuche, aeltester zuerst.
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly string $model,
        public readonly AiCallPurpose $purpose,
        public readonly AiCallStatus $status,
        public readonly string $promptVersion,
        public readonly string $promptHash,
        public readonly ?string $schemaKey = null,
        public readonly ?string $schemaVersion = null,
        public readonly ?string $schemaHash = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?int $estimatedCostCent = null,
        public readonly ?int $estimatedCostMilliCent = null,
        public readonly bool $costBasisAvailable = false,
        public readonly int $durationMs = 0,
        public readonly ?string $providerRequestId = null,
        public readonly array $providerRequestIds = [],
        public readonly string $correlationId = '',
        public readonly int $attempts = 1,
        public readonly ?int $httpStatusCode = null,
        public readonly bool $fallbackUsed = false,
        public readonly ?string $primaryProviderKey = null,
        public readonly ?string $fallbackReason = null,
        public readonly bool $dualReviewUsed = false,
        public readonly bool $fallbackBlockedByReleaseGate = false,
    ) {}

    public function withStatus(AiCallStatus $status): self
    {
        return $this->with(status: $status);
    }

    public function withFallback(string $primaryProviderKey, string $reason): self
    {
        return $this->with(
            fallbackUsed: true,
            primaryProviderKey: $primaryProviderKey,
            fallbackReason: $reason,
        );
    }

    public function withFallbackBlocked(string $reason): self
    {
        return $this->with(
            fallbackReason: $reason,
            fallbackBlockedByReleaseGate: true,
        );
    }

    public function withDualReview(): self
    {
        return $this->with(dualReviewUsed: true);
    }

    /**
     * Nur freigegebene Metadaten. Dieses Array ist der einzige zulaessige
     * Weg, einen KI-Aufruf zu protokollieren.
     *
     * @return array<string, scalar|null>
     */
    public function toLogContext(): array
    {
        return [
            'provider' => $this->providerKey,
            'model' => $this->model,
            'purpose' => $this->purpose->value,
            'result_status' => $this->status->value,
            'prompt_version' => $this->promptVersion,
            'prompt_hash' => $this->promptHash,
            'schema_key' => $this->schemaKey,
            'schema_version' => $this->schemaVersion,
            'schema_hash' => $this->schemaHash,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'estimated_cost_cent' => $this->estimatedCostCent,
            'duration_ms' => $this->durationMs,
            'request_id' => $this->providerRequestId,
            'correlation_id' => $this->correlationId,
            'attempts' => $this->attempts,
            'status_code' => $this->httpStatusCode,
            'fallback_used' => $this->fallbackUsed,
            'fallback_reason' => $this->fallbackReason,
            'dual_review_used' => $this->dualReviewUsed,
        ];
    }

    /**
     * @param  list<string>|null  $providerRequestIds
     */
    private function with(
        ?AiCallStatus $status = null,
        ?bool $fallbackUsed = null,
        ?string $primaryProviderKey = null,
        ?string $fallbackReason = null,
        ?bool $dualReviewUsed = null,
        ?bool $fallbackBlockedByReleaseGate = null,
        ?array $providerRequestIds = null,
    ): self {
        return new self(
            $this->providerKey,
            $this->model,
            $this->purpose,
            $status ?? $this->status,
            $this->promptVersion,
            $this->promptHash,
            $this->schemaKey,
            $this->schemaVersion,
            $this->schemaHash,
            $this->inputTokens,
            $this->outputTokens,
            $this->estimatedCostCent,
            $this->estimatedCostMilliCent,
            $this->costBasisAvailable,
            $this->durationMs,
            $this->providerRequestId,
            $providerRequestIds ?? $this->providerRequestIds,
            $this->correlationId,
            $this->attempts,
            $this->httpStatusCode,
            $fallbackUsed ?? $this->fallbackUsed,
            $primaryProviderKey ?? $this->primaryProviderKey,
            $fallbackReason ?? $this->fallbackReason,
            $dualReviewUsed ?? $this->dualReviewUsed,
            $fallbackBlockedByReleaseGate ?? $this->fallbackBlockedByReleaseGate,
        );
    }
}
