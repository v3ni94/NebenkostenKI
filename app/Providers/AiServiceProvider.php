<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Documents\Contracts\DocumentClassifier;
use App\Application\Documents\Contracts\DocumentExtractor;
use App\Application\Documents\Contracts\ProviderFileDeleter;
use App\Services\Ai\AiConfig;
use App\Services\Ai\AiDocumentProviderInterface;
use App\Services\Ai\AiProviderRouter;
use App\Services\Ai\AiServiceFactory;
use App\Services\Ai\DailyCostLimiter;
use App\Services\Ai\Http\AiHttpClientInterface;
use App\Services\Ai\Http\PsrAiHttpClient;
use App\Services\Ai\Integration\AiCallRecorder;
use App\Services\Ai\Integration\AiDocumentClassifier;
use App\Services\Ai\Integration\AiDocumentExtractor;
use App\Services\Ai\Integration\AiProviderFileDeleter;
use App\Services\Ai\Integration\DailyCostLedger;
use App\Services\Ai\Integration\DocumentPayloadFactory;
use App\Services\Ai\Integration\DocumentSchemaMap;
use App\Services\Ai\Integration\ExtractedFieldPersister;
use App\Services\Ai\Integration\PromptVersionRegistrar;
use App\Services\Ai\ProviderReleaseGate;
use App\Services\Ai\RedactingLogger;
use App\Services\Ai\Schemas\SchemaRegistry;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Verdrahtet die KI-Schicht und die Adapterschicht der Dokumentpipeline.
 *
 * WARUM EIN EIGENER PROVIDER UND KEINE BINDUNG IN AppServiceProvider:
 * AppServiceProvider traegt die Ratenbegrenzungen fuer Upload und Download und
 * ist damit ein HTTP-naher Baustein. Die KI-Anbindung ist ein fachlicher
 * Baustein mit eigenen Datenschutzregeln, eigener Freigabesperre und eigener
 * Kostenkontrolle. Eine eigene Datei haelt beide Themen getrennt, macht die
 * Verdrahtung in einem Blick pruefbar und erlaubt es, die KI-Anbindung im Test
 * gezielt zu ersetzen, ohne die Ratenbegrenzungen anzufassen. Registriert wird
 * der Provider in bootstrap/providers.php, der dafuer vorgesehenen
 * Providerliste von Laravel 12.
 *
 * OHNE DIESE BINDUNG BLEIBT DIE ANWENDUNG VOLLSTAENDIG UND DATENSCHUTZKONFORM:
 * AiPipelineResolver liefert dann null, der Teiljob meldet
 * KI_SCHICHT_NICHT_VERFUEGBAR, wird mit Backoff wiederholt und geht nach dem
 * letzten Versuch in den Dead-Letter-Status. Der Loeschpfad entfernt die
 * Quelldaten anschliessend sofort. Es bleiben also unter keinen Umstaenden
 * Originaldateien liegen, auch nicht bei fehlender, gesperrter oder gestoerter
 * KI-Anbindung. Dasselbe gilt fuer die Providerloeschung: ohne Bindung greift
 * NullProviderFileDeleter, und der lokale Loeschpfad laeuft unveraendert.
 *
 * FREIGABESPERRE: Der Testprovider ohne Netzwerkaufruf wird nur in den
 * Umgebungen local und testing aufgebaut und auch nur dann, wenn das
 * Fixture-Verzeichnis vorhanden ist. Produktiv sperrt ProviderReleaseGate ihn
 * zusaetzlich, und die externen Provider bleiben gesperrt, solange
 * AI_DATA_RETENTION_APPROVED auf false steht.
 */
class AiServiceProvider extends ServiceProvider
{
    /**
     * Verzeichnis der Beispielantworten des Testproviders. Es liegt bewusst im
     * Testbereich und ist produktiv nicht vorhanden.
     */
    public const FAKE_FIXTURE_DIRECTORY = 'tests/Fixtures/Ai';

    public function register(): void
    {
        $this->registerAiLayer();
        $this->registerIntegrationLayer();
        $this->registerPipelineContracts();
    }

    /**
     * Die KI-Schicht selbst. Sie kennt keine Persistenz und wird aus
     * config/ai.php aufgebaut.
     */
    private function registerAiLayer(): void
    {
        $this->app->singleton(AiConfig::class, static function (Application $app): AiConfig {
            /** @var array<string, mixed> $config */
            $config = $app->make('config')->get('ai', []);

            return AiConfig::fromArray($config);
        });

        $this->app->singleton(AiServiceFactory::class, function (Application $app): AiServiceFactory {
            return new AiServiceFactory(
                $app->make(AiConfig::class),
                (string) $app->environment(),
                $app->make('log'),
                null,
                $this->fakeFixtureDirectory($app),
            );
        });

        $this->app->singleton(
            AiProviderRouter::class,
            static fn (Application $app): AiProviderRouter => $app->make(AiServiceFactory::class)->makeRouter(),
        );

        $this->app->singleton(
            AiDocumentProviderInterface::class,
            static fn (Application $app): AiDocumentProviderInterface => $app->make(AiProviderRouter::class),
        );

        $this->app->singleton(
            ProviderReleaseGate::class,
            static fn (Application $app): ProviderReleaseGate => $app->make(AiServiceFactory::class)->releaseGate(),
        );

        $this->app->singleton(SchemaRegistry::class, static fn (): SchemaRegistry => new SchemaRegistry);

        $this->app->singleton(
            DailyCostLimiter::class,
            static fn (Application $app): DailyCostLimiter => DailyCostLimiter::fromConfig($app->make(AiConfig::class)),
        );

        $this->app->singleton(
            RedactingLogger::class,
            static fn (Application $app): RedactingLogger => new RedactingLogger($app->make('log')),
        );

        // Transport ausschliesslich fuer den Loeschpfad. Die Providerklassen
        // erhalten ihren Client von AiServiceFactory. Der Loeschpfad braucht
        // einen eigenen, weil er ohne Extraktionslauf aufgerufen wird.
        $this->app->singleton(AiHttpClientInterface::class, static function (Application $app): AiHttpClientInterface {
            $config = $app->make(AiConfig::class);
            $primary = $config->provider($config->primaryProvider);
            $timeout = $primary === null ? 120 : $primary->timeoutSeconds;
            $factory = new HttpFactory;

            return new PsrAiHttpClient(
                new GuzzleClient([
                    'timeout' => $timeout,
                    'connect_timeout' => min(20, $timeout),
                    'http_errors' => false,
                ]),
                $factory,
                $factory,
                $config->primaryProvider->value,
            );
        });
    }

    /**
     * Die Adapterschicht: Persistenz, Kostenkontrolle und Nachweise.
     */
    private function registerIntegrationLayer(): void
    {
        $this->app->singleton(DocumentPayloadFactory::class);
        $this->app->singleton(PromptVersionRegistrar::class);
        $this->app->singleton(AiCallRecorder::class);

        $this->app->singleton(
            DocumentSchemaMap::class,
            static fn (Application $app): DocumentSchemaMap => new DocumentSchemaMap($app->make(SchemaRegistry::class)),
        );

        $this->app->singleton(
            DailyCostLedger::class,
            static fn (Application $app): DailyCostLedger => new DailyCostLedger($app->make(DailyCostLimiter::class)),
        );

        $this->app->singleton(
            ExtractedFieldPersister::class,
            static fn (Application $app): ExtractedFieldPersister => new ExtractedFieldPersister(
                $app->make(AiConfig::class)->confidenceReviewThreshold,
            ),
        );
    }

    /**
     * Die drei Vertraege der Dokumentpipeline. Erst diese Bindungen fuehren
     * dazu, dass die Pipeline tatsaechlich auswertet statt bis Dead Letter zu
     * laufen.
     */
    private function registerPipelineContracts(): void
    {
        if (! $this->bindsDocumentPipeline()) {
            return;
        }

        $this->app->bind(DocumentClassifier::class, AiDocumentClassifier::class);
        $this->app->bind(DocumentExtractor::class, AiDocumentExtractor::class);
        $this->app->bind(ProviderFileDeleter::class, AiProviderFileDeleter::class);
    }

    /**
     * Sollen die Pipeline-Vertraege gebunden werden?
     *
     * Standard ist ja, ausser in der Umgebung testing. Grund: Die Testsuite
     * prueft den Lebenszyklus der Dokumentpipeline ausdruecklich auch fuer den
     * Zustand OHNE KI-Anbindung, also Wiederholung mit Backoff, Dead Letter
     * und sofortige Loeschung der Quelldaten. Eine pauschale Bindung wuerde
     * diesen Zustand unerreichbar machen und damit einen Nachweis des
     * Loeschkonzepts entwerten. Tests, die mit KI-Anbindung laufen sollen,
     * schalten sie ausdruecklich ueber ai.bind_document_pipeline ein.
     *
     * Der Schalter ist bewusst dreiwertig: ist er nicht gesetzt, entscheidet
     * die Umgebung. Ein ausdruecklich gesetzter Wert hat Vorrang, damit der
     * Betrieb die Anbindung notfalls ohne Codeaenderung abschalten kann. Ohne
     * Bindung bleibt der Ablauf vollstaendig und datenschutzkonform, siehe
     * Klassenkommentar.
     */
    private function bindsDocumentPipeline(): bool
    {
        $configured = $this->app->make('config')->get('ai.bind_document_pipeline');

        if (is_bool($configured)) {
            return $configured;
        }

        return ! $this->app->environment('testing');
    }

    /**
     * Fixture-Verzeichnis des Testproviders oder null.
     */
    private function fakeFixtureDirectory(Application $app): ?string
    {
        if (! in_array($app->environment(), ProviderReleaseGate::NON_PRODUCTION_ENVIRONMENTS, true)) {
            return null;
        }

        $directory = $app->basePath(self::FAKE_FIXTURE_DIRECTORY);

        return is_dir($directory) ? $directory : null;
    }
}
