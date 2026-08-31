<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Http\AiHttpClientInterface;
use App\Services\Ai\Http\PsrAiHttpClient;
use App\Services\Ai\Prompts\PromptRegistry;
use App\Services\Ai\Providers\AnthropicMessagesProvider;
use App\Services\Ai\Providers\FakeAiProvider;
use App\Services\Ai\Providers\OpenAiResponsesProvider;
use App\Services\Ai\Schemas\SchemaRegistry;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * Baut die KI-Schicht aus einem Konfigurationsarray zusammen.
 *
 * Diese Klasse ist der einzige Ort der Schicht, der die Verdrahtung kennt.
 * Alle uebrigen Klassen erhalten ihre Abhaengigkeiten injiziert und bleiben
 * ohne Framework-Bootstrap testbar (ADR-001).
 *
 * Aufruf aus der Application-Schicht:
 *
 *     $router = AiServiceFactory::fromConfigArray(config('ai'), app()->environment(), logger())
 *         ->makeRouter();
 *
 * VERBINDLICH: Der Testprovider liest Beispielantworten aus einem
 * Fixture-Verzeichnis. Er wird nur angelegt, wenn ein Verzeichnis uebergeben
 * ist. Produktiv sperrt ProviderReleaseGate ihn ohnehin.
 */
final class AiServiceFactory
{
    public function __construct(
        private readonly AiConfig $config,
        private readonly string $environment,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?AiHttpClientInterface $httpClient = null,
        private readonly ?string $fakeFixtureDirectory = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config  Inhalt von config('ai').
     */
    public static function fromConfigArray(
        array $config,
        string $environment,
        ?LoggerInterface $logger = null,
        ?AiHttpClientInterface $httpClient = null,
        ?string $fakeFixtureDirectory = null,
    ): self {
        return new self(
            AiConfig::fromArray($config),
            $environment,
            $logger,
            $httpClient,
            $fakeFixtureDirectory,
        );
    }

    public function config(): AiConfig
    {
        return $this->config;
    }

    public function releaseGate(): ProviderReleaseGate
    {
        return ProviderReleaseGate::fromConfig($this->config, $this->environment);
    }

    public function schemaRegistry(): SchemaRegistry
    {
        return new SchemaRegistry;
    }

    public function promptRegistry(): PromptRegistry
    {
        return new PromptRegistry($this->config->securityPrompt);
    }

    public function redactingLogger(): RedactingLogger
    {
        return new RedactingLogger($this->logger);
    }

    public function makeRouter(): AiProviderRouter
    {
        return new AiProviderRouter(
            $this->config,
            $this->releaseGate(),
            new DualReviewComparator,
            $this->redactingLogger(),
            $this->makeProviders(),
        );
    }

    /**
     * @return array<string, AiDocumentProviderInterface>
     */
    public function makeProviders(): array
    {
        $providers = [];

        foreach ([AiProviderKey::OPENAI, AiProviderKey::ANTHROPIC, AiProviderKey::FAKE] as $key) {
            $provider = $this->makeProvider($key);

            if ($provider !== null) {
                $providers[$key->value] = $provider;
            }
        }

        return $providers;
    }

    public function makeProvider(AiProviderKey $key): ?AiDocumentProviderInterface
    {
        $schemas = $this->schemaRegistry();
        $prompts = $this->promptRegistry();
        $validator = new JsonSchemaValidator;
        $confidence = new ConfidenceEvaluator($this->config->confidenceReviewThreshold);
        $costEstimator = CostEstimator::fromConfig($this->config);
        $costLimiter = DailyCostLimiter::fromConfig($this->config);
        $logger = $this->redactingLogger();

        if ($key === AiProviderKey::FAKE) {
            $directory = $this->fakeFixtureDirectory;

            if ($directory === null) {
                return null;
            }

            return new FakeAiProvider(
                $directory,
                $schemas,
                $prompts,
                $validator,
                $confidence,
                $costEstimator,
                $logger,
            );
        }

        $providerConfig = $this->config->provider($key);

        if ($providerConfig === null) {
            return null;
        }

        $httpClient = $this->httpClient ?? $this->makeHttpClient($providerConfig);

        return match ($key) {
            AiProviderKey::OPENAI => new OpenAiResponsesProvider(
                $providerConfig,
                $httpClient,
                $schemas,
                $prompts,
                $validator,
                $confidence,
                $costEstimator,
                $costLimiter,
                $logger,
                $this->config->maxRetries,
            ),
            AiProviderKey::ANTHROPIC => new AnthropicMessagesProvider(
                $providerConfig,
                $httpClient,
                $schemas,
                $prompts,
                $validator,
                $confidence,
                $costEstimator,
                $costLimiter,
                $logger,
                $this->config->maxRetries,
            ),
            AiProviderKey::FAKE => null,
        };
    }

    private function makeHttpClient(ProviderConfig $providerConfig): AiHttpClientInterface
    {
        $factory = new HttpFactory;

        return new PsrAiHttpClient(
            new GuzzleClient([
                'timeout' => $providerConfig->timeoutSeconds,
                'connect_timeout' => min(20, $providerConfig->timeoutSeconds),
                'http_errors' => false,
            ]),
            $factory,
            $factory,
            $providerConfig->key->value,
        );
    }
}
