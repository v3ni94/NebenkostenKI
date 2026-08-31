<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Konfiguration eines einzelnen Providers.
 *
 * Der API-Key wird nur gehalten, nie protokolliert und nie in eine Meldung
 * uebernommen. hasApiKey() ist der einzige zulaessige Weg, den Zustand nach
 * aussen zu zeigen.
 */
final class ProviderConfig
{
    public function __construct(
        public readonly AiProviderKey $key,
        private readonly ?string $apiKey,
        public readonly string $baseUri,
        public readonly string $modelExtract,
        public readonly string $modelAnalyze,
        public readonly int $timeoutSeconds = 120,
        public readonly bool $storeResponses = false,
        public readonly ?string $apiVersion = null,
    ) {}

    public function apiKey(): ?string
    {
        return $this->apiKey;
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== null && trim($this->apiKey) !== '';
    }

    public function model(bool $analyze): string
    {
        return $analyze ? $this->modelAnalyze : $this->modelExtract;
    }

    /**
     * Endpunkt-URL aus Basis-URI und Pfad, ohne doppelte Schraegstriche.
     */
    public function endpoint(string $path): string
    {
        return rtrim($this->baseUri, '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(AiProviderKey $key, array $config): self
    {
        return new self(
            $key,
            is_string($config['api_key'] ?? null) ? $config['api_key'] : null,
            is_string($config['base_uri'] ?? null) ? $config['base_uri'] : '',
            is_string($config['model_extract'] ?? null) ? $config['model_extract'] : '',
            is_string($config['model_analyze'] ?? null) ? $config['model_analyze'] : '',
            is_numeric($config['timeout_seconds'] ?? null) ? (int) $config['timeout_seconds'] : 120,
            (bool) ($config['store_responses'] ?? false),
            is_string($config['version'] ?? null) ? $config['version'] : null,
        );
    }
}
