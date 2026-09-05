<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Services\Ai\AiConfig;
use App\Services\Ai\AiDocumentProviderInterface;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\AiProviderRouter;
use App\Services\Ai\AiServiceFactory;
use App\Services\Ai\Dto\AiResultStatus;
use App\Services\Ai\Dto\AnalyzeContractRequest;
use App\Services\Ai\Dto\ClassifyDocumentRequest;
use App\Services\Ai\Dto\ExtractStructuredDataRequest;
use App\Services\Ai\Dto\HealthCheckRequest;
use App\Services\Ai\DualReviewComparator;
use App\Services\Ai\Exceptions\ProviderNotReleasedException;
use App\Services\Ai\Exceptions\ProviderTransportException;
use App\Services\Ai\Exceptions\RateLimitException;
use App\Services\Ai\Exceptions\UnsupportedFileTypeException;
use App\Services\Ai\ProviderReleaseGate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;
use Tests\Unit\Ai\Support\CollectingLogger;
use Tests\Unit\Ai\Support\CountingAiProvider;
use Tests\Unit\Ai\Support\RecordingAiHttpClient;

/**
 * Routing, Fallback, Dual Review und Freigabesperre (Abschnitt 13.5).
 *
 * Es findet KEIN Netzwerkaufruf und damit kein kostenpflichtiger
 * Providerzugriff statt. Alle Beispieldaten sind frei erfunden.
 */
final class AiProviderRoutingTest extends TestCase
{
    public function test_standardweg_sendet_nur_an_einen_provider(): void
    {
        $primary = new CountingAiProvider('openai');
        $fallback = new CountingAiProvider('anthropic');

        $router = $this->router(['fallback_enabled' => true], $primary, $fallback);

        $result = $router->extractStructuredData($this->extractRequest());

        self::assertTrue($result->isValidated());
        self::assertSame(1, $primary->calls);
        self::assertSame(0, $fallback->calls, 'Datenminimierung: nur ein Provider.');
        self::assertFalse($result->metadata->fallbackUsed);
    }

    public function test_fallback_greift_bei_transportfehler(): void
    {
        $primary = (new CountingAiProvider('openai'))->failWith(
            ProviderTransportException::httpStatus('openai', 500),
        );
        $fallback = new CountingAiProvider('anthropic');

        $router = $this->router(['fallback_enabled' => true], $primary, $fallback);

        $result = $router->extractStructuredData($this->extractRequest());

        self::assertSame(1, $primary->calls);
        self::assertSame(1, $fallback->calls);
        self::assertTrue($result->metadata->fallbackUsed);
        self::assertSame('openai', $result->metadata->primaryProviderKey);
        self::assertSame(AiProviderRouter::FALLBACK_REASON_TRANSPORT, $result->metadata->fallbackReason);
    }

    public function test_fallback_greift_bei_rate_limit(): void
    {
        $primary = (new CountingAiProvider('openai'))->failWith(RateLimitException::forProvider('openai', 30));
        $fallback = new CountingAiProvider('anthropic');

        $router = $this->router(['fallback_enabled' => true], $primary, $fallback);

        $result = $router->extractStructuredData($this->extractRequest());

        self::assertSame(1, $fallback->calls);
        self::assertSame(AiProviderRouter::FALLBACK_REASON_RATE_LIMIT, $result->metadata->fallbackReason);
    }

    public function test_fallback_greift_bei_nicht_unterstuetztem_dateityp(): void
    {
        $primary = (new CountingAiProvider('openai'))->failWith(
            UnsupportedFileTypeException::forMimeType('openai', 'image/heic'),
        );
        $fallback = new CountingAiProvider('anthropic');

        $router = $this->router(['fallback_enabled' => true], $primary, $fallback);

        $result = $router->extractStructuredData($this->extractRequest());

        self::assertSame(1, $fallback->calls);
        self::assertSame(AiProviderRouter::FALLBACK_REASON_UNSUPPORTED_FILE, $result->metadata->fallbackReason);
    }

    public function test_fallback_greift_bei_wiederholter_schemaverletzung(): void
    {
        $primary = (new CountingAiProvider('openai'))->returnSchemaFailure();
        $fallback = new CountingAiProvider('anthropic');

        $router = $this->router(['fallback_enabled' => true], $primary, $fallback);

        $result = $router->extractStructuredData($this->extractRequest());

        self::assertSame(1, $primary->calls);
        self::assertSame(1, $fallback->calls);
        self::assertTrue($result->isValidated());
        self::assertSame(AiProviderRouter::FALLBACK_REASON_SCHEMA, $result->metadata->fallbackReason);
    }

    public function test_fallback_greift_nicht_bei_fachlich_unplausiblem_aber_schemakonformem_ergebnis(): void
    {
        // Frei erfundenes, fachlich unplausibles Ergebnis: die Abrechnungsspitze
        // ist absurd hoch, das Ergebnis ist aber schemakonform.
        $payload = AiTestFactory::fixtureArray('hausgeldabrechnung.json');
        $payload['abrechnungsspitze_cent']['value'] = 99_999_999_99;

        $primary = (new CountingAiProvider('openai'))->withPayload($payload);
        $fallback = new CountingAiProvider('anthropic');

        $router = $this->router(['fallback_enabled' => true], $primary, $fallback);

        $result = $router->extractStructuredData($this->extractRequest());

        self::assertTrue($result->isValidated());
        self::assertSame(1, $primary->calls);
        self::assertSame(
            0,
            $fallback->calls,
            'Fachliche Plausibilitaet prueft die Regel-Engine, nicht ein zweiter Provider.',
        );
        self::assertFalse($result->metadata->fallbackUsed);
    }

    public function test_ohne_aktivierten_fallback_wird_die_ausnahme_durchgereicht(): void
    {
        $primary = (new CountingAiProvider('openai'))->failWith(RateLimitException::forProvider('openai'));
        $fallback = new CountingAiProvider('anthropic');

        $router = $this->router(['fallback_enabled' => false], $primary, $fallback);

        try {
            $router->extractStructuredData($this->extractRequest());
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (RateLimitException) {
            self::assertSame(0, $fallback->calls);
        }
    }

    public function test_ohne_aktivierten_fallback_bleibt_der_schemafehler_bestehen(): void
    {
        $primary = (new CountingAiProvider('openai'))->returnSchemaFailure();
        $fallback = new CountingAiProvider('anthropic');

        $router = $this->router(['fallback_enabled' => false], $primary, $fallback);

        $result = $router->extractStructuredData($this->extractRequest());

        self::assertTrue($result->requiresManualEntry());
        self::assertSame(0, $fallback->calls);
    }

    public function test_klassifikation_faellt_ebenfalls_zurueck(): void
    {
        $primary = (new CountingAiProvider('openai'))->failWith(RateLimitException::forProvider('openai'));
        $fallback = new CountingAiProvider('anthropic');

        $router = $this->router(['fallback_enabled' => true], $primary, $fallback);

        $result = $router->classifyDocument(new ClassifyDocumentRequest(
            AiTestFactory::pdfPayload(),
            AiTestFactory::context(),
        ));

        self::assertSame(1, $fallback->calls);
        self::assertSame(DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, $result->documentType);
        self::assertTrue($result->metadata()->fallbackUsed);
    }

    // -----------------------------------------------------------------
    // Freigabesperre
    // -----------------------------------------------------------------

    public function test_nicht_freigegebener_primaerprovider_wird_produktiv_blockiert(): void
    {
        $primary = new CountingAiProvider('openai');
        $fallback = new CountingAiProvider('anthropic');

        $router = $this->router(
            ['data_retention_approved' => false, 'fallback_enabled' => true],
            $primary,
            $fallback,
            'production',
        );

        try {
            $router->extractStructuredData($this->extractRequest());
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (ProviderNotReleasedException $exception) {
            self::assertSame(0, $primary->calls);
            self::assertSame(0, $fallback->calls);
            self::assertStringContainsString('AI_DATA_RETENTION_APPROVED', $exception->getMessage());
        }
    }

    public function test_freigabesperre_wird_durch_den_fallback_nicht_umgangen(): void
    {
        // Der Primaerprovider ist freigegeben, weil er in dieser Konstellation
        // ausdruecklich zugelassen wurde. Der Fallbackprovider ist es nicht.
        $primary = (new CountingAiProvider('openai'))->failWith(
            ProviderTransportException::httpStatus('openai', 503),
        );
        $fallback = new CountingAiProvider('anthropic');

        $collector = new CollectingLogger;

        $router = new AiProviderRouter(
            AiTestFactory::config(['fallback_enabled' => true, 'data_retention_approved' => false]),
            new ProviderReleaseGate(true, false, 'production', ['openai']),
            new DualReviewComparator,
            AiTestFactory::logger($collector),
            ['openai' => $primary, 'anthropic' => $fallback],
        );

        try {
            $router->extractStructuredData($this->extractRequest());
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (ProviderTransportException $exception) {
            self::assertSame(1, $primary->calls);
            self::assertSame(
                0,
                $fallback->calls,
                'Ein Fallback darf die Freigabesperre nicht umgehen.',
            );
            self::assertStringContainsString('503', $exception->getMessage());
            self::assertStringContainsString('Fallback durch Freigabesperre blockiert', $collector->dump());
        }
    }

    public function test_testprovider_laeuft_in_der_testumgebung(): void
    {
        $router = new AiProviderRouter(
            AiTestFactory::config([
                'primary_provider' => 'fake',
                'fallback_provider' => null,
                'fallback_enabled' => false,
                'data_retention_approved' => false,
            ]),
            new ProviderReleaseGate(true, false, 'testing'),
            new DualReviewComparator,
            AiTestFactory::logger(),
            ['fake' => AiTestFactory::fakeProvider()],
        );

        $result = $router->extractStructuredData($this->extractRequest());

        self::assertTrue($result->isValidated());
        self::assertSame('fake', $result->metadata->providerKey);
    }

    public function test_testprovider_ist_produktiv_blockiert(): void
    {
        $router = new AiProviderRouter(
            AiTestFactory::config(['primary_provider' => 'fake', 'fallback_enabled' => false]),
            new ProviderReleaseGate(true, true, 'production'),
            new DualReviewComparator,
            AiTestFactory::logger(),
            ['fake' => AiTestFactory::fakeProvider()],
        );

        $this->expectException(ProviderNotReleasedException::class);

        $router->extractStructuredData($this->extractRequest());
    }

    // -----------------------------------------------------------------
    // Dual Review
    // -----------------------------------------------------------------

    public function test_dual_review_liefert_bei_widerspruch_einen_konflikt(): void
    {
        $abweichend = AiTestFactory::fixtureArray('hausgeldabrechnung.json');
        $abweichend['abrechnungsspitze_cent']['value'] = 20000;
        $abweichend['verwalterverguetung_cent']['value'] = 31000;

        $primary = new CountingAiProvider('openai');
        $secondary = (new CountingAiProvider('anthropic'))->withPayload($abweichend);

        $router = $this->router(['dual_review_enabled' => true, 'fallback_enabled' => true], $primary, $secondary);

        $result = $router->extractStructuredData($this->extractRequest());

        self::assertSame(1, $primary->calls);
        self::assertSame(1, $secondary->calls);
        self::assertSame(AiResultStatus::KONFLIKT, $result->status);
        self::assertTrue($result->hasConflict());
        self::assertNotNull($result->conflictReport);
        self::assertSame(['abrechnungsspitze_cent', 'verwalterverguetung_cent'], $result->conflictReport->paths());

        $entry = $result->conflictReport->entries[0];
        self::assertSame('openai', $entry->providerKeyA);
        self::assertSame(18450, $entry->valueA);
        self::assertSame('anthropic', $entry->providerKeyB);
        self::assertSame(20000, $entry->valueB);
    }

    public function test_dual_review_faellt_kein_mehrheitsentscheid(): void
    {
        $abweichend = AiTestFactory::fixtureArray('hausgeldabrechnung.json');
        // Der zweite Provider ist deutlich konfidenter. Das darf den
        // Widerspruch nicht aufloesen.
        $abweichend['abrechnungsspitze_cent']['value'] = 20000;
        $abweichend['abrechnungsspitze_cent']['confidence'] = 1.0;

        $primary = new CountingAiProvider('openai');
        $secondary = (new CountingAiProvider('anthropic'))->withPayload($abweichend);

        $router = $this->router(['dual_review_enabled' => true, 'fallback_enabled' => true], $primary, $secondary);

        $result = $router->extractStructuredData($this->extractRequest());

        self::assertSame(AiResultStatus::KONFLIKT, $result->status);
        self::assertSame(
            18450,
            $result->field('abrechnungsspitze_cent')?->value,
            'Der Wert des Primaerprovders bleibt unveraendert, es wird nichts ersetzt.',
        );
        self::assertNotNull($result->conflictReport);
        self::assertCount(1, $result->conflictReport->entries);
        self::assertSame(1.0, $result->conflictReport->entries[0]->confidenceB);
    }

    public function test_dual_review_ohne_widerspruch_liefert_ein_regulaeres_ergebnis(): void
    {
        $primary = new CountingAiProvider('openai');
        $secondary = new CountingAiProvider('anthropic');

        $router = $this->router(['dual_review_enabled' => true, 'fallback_enabled' => true], $primary, $secondary);

        $result = $router->extractStructuredData($this->extractRequest());

        self::assertSame(AiResultStatus::VALIDIERT, $result->status);
        self::assertNull($result->conflictReport);
        self::assertTrue($result->metadata->dualReviewUsed);
    }

    public function test_dual_review_wird_durch_die_freigabesperre_verhindert(): void
    {
        $primary = new CountingAiProvider('openai');
        $secondary = new CountingAiProvider('anthropic');

        $router = new AiProviderRouter(
            AiTestFactory::config(['dual_review_enabled' => true, 'fallback_enabled' => true]),
            new ProviderReleaseGate(true, false, 'production', ['openai']),
            new DualReviewComparator,
            AiTestFactory::logger(),
            ['openai' => $primary, 'anthropic' => $secondary],
        );

        try {
            $router->extractStructuredData($this->extractRequest());
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (ProviderNotReleasedException) {
            self::assertSame(0, $secondary->calls);
        }
    }

    // -----------------------------------------------------------------
    // Konfiguration und Healthcheck
    // -----------------------------------------------------------------

    public function test_healthcheck_ergaenzt_den_freigabestatus(): void
    {
        $router = $this->router([], new CountingAiProvider('openai'), new CountingAiProvider('anthropic'), 'production');

        $result = $router->healthCheck(new HealthCheckRequest(AiTestFactory::context()));

        self::assertTrue($result->releasedForProduction);
        self::assertTrue($result->isUsable());
    }

    public function test_healthcheck_meldet_fehlende_freigabe(): void
    {
        $router = $this->router(
            ['data_retention_approved' => false],
            new CountingAiProvider('openai'),
            new CountingAiProvider('anthropic'),
            'production',
        );

        $results = $router->healthCheckAll(new HealthCheckRequest(AiTestFactory::context()));

        self::assertArrayHasKey('openai', $results);
        self::assertArrayHasKey('anthropic', $results);
        self::assertFalse($results['openai']->releasedForProduction);
        self::assertFalse($results['openai']->isUsable());
        self::assertStringContainsString('Freigabe fehlt', $results['openai']->message);
    }

    #[DataProvider('providerKeyProvider')]
    public function test_konfigurierte_providerschluessel_werden_erkannt(string $key, AiProviderKey $expected): void
    {
        self::assertSame($expected, AiProviderKey::tryFromKey($key));
    }

    /**
     * @return list<array{0: string, 1: AiProviderKey}>
     */
    public static function providerKeyProvider(): array
    {
        return [
            ['openai', AiProviderKey::OPENAI],
            ['ANTHROPIC', AiProviderKey::ANTHROPIC],
            [' fake ', AiProviderKey::FAKE],
        ];
    }

    public function test_projektkonfiguration_ist_lesbar_und_setzt_den_testprovider(): void
    {
        /** @var array<string, mixed> $raw */
        $raw = config('ai');
        $config = AiConfig::fromArray($raw);

        self::assertSame(AiProviderKey::FAKE, $config->primaryProvider);
        self::assertFalse($config->fallbackEnabled);
        self::assertFalse($config->dataRetentionApproved);
        self::assertTrue($config->requireZeroDataRetention);
        self::assertSame(2, $config->maxRetries);
        self::assertNotSame('', $config->securityPrompt);
        self::assertStringContainsString('untrusted data', $config->securityPrompt);
    }

    public function test_factory_baut_den_router_aus_der_projektkonfiguration(): void
    {
        /** @var array<string, mixed> $raw */
        $raw = config('ai');

        $factory = AiServiceFactory::fromConfigArray(
            $raw,
            'testing',
            null,
            new RecordingAiHttpClient,
            AiTestFactory::fixtureDirectory(),
        );

        $router = $factory->makeRouter();

        self::assertSame(AiProviderKey::FAKE, $router->primaryProviderKey());
        self::assertTrue($factory->releaseGate()->isReleased(AiProviderKey::FAKE));
        self::assertFalse($factory->releaseGate()->isReleased(AiProviderKey::OPENAI));

        $result = $router->extractStructuredData($this->extractRequest());

        self::assertTrue($result->isValidated());
        self::assertSame('fake', $result->metadata->providerKey);
    }

    public function test_vertragsanalyse_wird_ebenfalls_geroutet(): void
    {
        $primary = (new CountingAiProvider('openai'))->failWith(
            ProviderTransportException::network('openai'),
        );
        $fallback = new CountingAiProvider('anthropic');

        $router = $this->router(['fallback_enabled' => true], $primary, $fallback);

        $result = $router->analyzeContract(new AnalyzeContractRequest(
            AiTestFactory::pdfPayload(),
            AiTestFactory::context(),
        ));

        self::assertSame(1, $fallback->calls);
        self::assertTrue($result->metadata->fallbackUsed);
    }

    // -----------------------------------------------------------------
    // Hilfsmittel
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $configOverrides
     */
    private function router(
        array $configOverrides,
        AiDocumentProviderInterface $primary,
        AiDocumentProviderInterface $fallback,
        string $environment = 'testing',
    ): AiProviderRouter {
        $config = AiTestFactory::config($configOverrides);

        return new AiProviderRouter(
            $config,
            ProviderReleaseGate::fromConfig($config, $environment),
            new DualReviewComparator,
            AiTestFactory::logger(),
            ['openai' => $primary, 'anthropic' => $fallback],
        );
    }

    private function extractRequest(): ExtractStructuredDataRequest
    {
        return new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'hausgeldabrechnung',
            AiTestFactory::context(),
        );
    }
}
