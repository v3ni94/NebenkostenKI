<?php

declare(strict_types=1);

namespace App\Services\Ai;

use InvalidArgumentException;

/**
 * Typisierte Sicht auf config/ai.php.
 *
 * Die KI-Schicht liest die Konfiguration nicht selbst ueber Laravel-Helfer,
 * sondern erhaelt sie als Wertobjekt. Damit bleibt jede Klasse der Schicht
 * ohne Framework-Bootstrap testbar (ADR-001: die fachlichen Schichten haengen
 * nicht an HTTP oder Facades).
 */
final class AiConfig
{
    /**
     * @param  array<string, ProviderConfig>  $providers
     * @param  array<string, array{input: int, output: int}>  $costBasisUsCentPerMillionTokens
     */
    public function __construct(
        public readonly AiProviderKey $primaryProvider,
        public readonly ?AiProviderKey $fallbackProvider,
        public readonly bool $fallbackEnabled,
        public readonly bool $dualReviewEnabled,
        public readonly bool $requireZeroDataRetention,
        public readonly bool $dataRetentionApproved,
        public readonly float $confidenceReviewThreshold,
        public readonly int $maxRetries,
        public readonly ?int $maxDailyCostCentPerUser,
        public readonly array $providers,
        public readonly array $costBasisUsCentPerMillionTokens,
        public readonly string $securityPrompt,
    ) {}

    /**
     * @param  array<string, mixed>  $config  Inhalt von config('ai').
     */
    public static function fromArray(array $config): self
    {
        $primary = AiProviderKey::tryFromKey(is_string($config['primary_provider'] ?? null) ? $config['primary_provider'] : null);

        if ($primary === null) {
            throw new InvalidArgumentException(
                'ai.primary_provider muss openai, anthropic oder fake sein.'
            );
        }

        $providers = [];
        $rawProviders = is_array($config['providers'] ?? null) ? $config['providers'] : [];

        foreach ($rawProviders as $key => $providerConfig) {
            $providerKey = AiProviderKey::tryFromKey(is_string($key) ? $key : null);

            if ($providerKey === null || ! is_array($providerConfig)) {
                continue;
            }

            /** @var array<string, mixed> $providerConfig */
            $providers[$providerKey->value] = ProviderConfig::fromArray($providerKey, $providerConfig);
        }

        $maxRetries = is_numeric($config['max_retries'] ?? null) ? (int) $config['max_retries'] : 2;

        return new self(
            $primary,
            AiProviderKey::tryFromKey(is_string($config['fallback_provider'] ?? null) ? $config['fallback_provider'] : null),
            (bool) ($config['fallback_enabled'] ?? false),
            (bool) ($config['dual_review_enabled'] ?? false),
            (bool) ($config['require_zero_data_retention'] ?? true),
            (bool) ($config['data_retention_approved'] ?? false),
            is_numeric($config['confidence_review_threshold'] ?? null)
                ? (float) $config['confidence_review_threshold']
                : 0.80,
            max(0, min(5, $maxRetries)),
            is_numeric($config['max_daily_cost_cent_per_user'] ?? null)
                ? (int) $config['max_daily_cost_cent_per_user']
                : null,
            $providers,
            self::normalizeCostBasis($config['cost_basis_us_cent_per_million_tokens'] ?? null),
            is_string($config['security_prompt'] ?? null) ? $config['security_prompt'] : '',
        );
    }

    public function provider(AiProviderKey $key): ?ProviderConfig
    {
        return $this->providers[$key->value] ?? null;
    }

    public function requireProvider(AiProviderKey $key): ProviderConfig
    {
        $provider = $this->provider($key);

        if ($provider === null) {
            throw new InvalidArgumentException(sprintf(
                'Fuer den Provider "%s" ist keine Konfiguration hinterlegt.',
                $key->value,
            ));
        }

        return $provider;
    }

    /**
     * @return array<string, array{input: int, output: int}>
     */
    private static function normalizeCostBasis(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $basis = [];

        foreach ($raw as $model => $prices) {
            if (! is_string($model) || ! is_array($prices)) {
                continue;
            }

            if (! is_numeric($prices['input'] ?? null) || ! is_numeric($prices['output'] ?? null)) {
                continue;
            }

            $basis[$model] = [
                'input' => (int) $prices['input'],
                'output' => (int) $prices['output'],
            ];
        }

        return $basis;
    }
}
