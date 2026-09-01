<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AiCallPurpose;
use App\Models\AiCall;
use App\Models\AiPromptVersion;
use App\Models\BillingRun;
use App\Services\Ai\AiProviderRouter;
use App\Services\Ai\AiServiceFactory;
use Tests\Unit\Ai\Support\RecordingAiHttpClient;

/**
 * KI-Bereich des Adminbereichs (Masterprompt 13.2, 13.8, 20).
 *
 * VERBINDLICH: Im Test findet KEIN echter Providerabruf statt. Der
 * Transportadapter der KI-Schicht wird durch RecordingAiHttpClient ersetzt,
 * der Healthcheck laeuft unveraendert ueber die Providerabstraktion.
 */
final class KiBereichTest extends AdminTestCase
{
    /**
     * Frei erfundene Platzhalter. Es werden keine echten Schluessel verwendet.
     */
    private const string OPENAI_KEY = 'sk-test-openai-platzhalter-0001';

    private const string ANTHROPIC_KEY = 'sk-ant-test-platzhalter-0002';

    private RecordingAiHttpClient $transport;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.primary_provider', 'openai');
        config()->set('ai.fallback_provider', 'anthropic');
        config()->set('ai.fallback_enabled', true);
        config()->set('ai.providers.openai.api_key', self::OPENAI_KEY);
        config()->set('ai.providers.anthropic.api_key', self::ANTHROPIC_KEY);

        $this->transport = new RecordingAiHttpClient;
    }

    /**
     * Ersetzt den Router im Container durch einen Router mit Testtransport.
     */
    private function verdrahteTransport(): void
    {
        /** @var array<string, mixed> $config */
        $config = config('ai');

        $router = AiServiceFactory::fromConfigArray(
            $config,
            'testing',
            null,
            $this->transport,
        )->makeRouter();

        $this->app->instance(AiProviderRouter::class, $router);
    }

    public function test_der_healthcheck_meldet_erreichbarkeit_und_verfuegbarkeit_des_modells(): void
    {
        $this->transport->setDefaultJson(['id' => 'modell', 'object' => 'model']);
        $this->verdrahteTransport();

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/ki');

        $antwort->assertOk();
        $antwort->assertSee('Healthcheck je Provider');
        $antwort->assertSee('openai');
        $antwort->assertSee('anthropic');
        $antwort->assertSee((string) config('ai.providers.openai.model_extract'));

        // Es hat ein Aufruf ueber den Testtransport stattgefunden, also kein
        // echter Providerabruf.
        self::assertGreaterThan(0, $this->transport->callCount());
    }

    public function test_ein_nicht_verfuegbares_modell_wird_als_solches_gemeldet(): void
    {
        $this->transport->setDefaultJson(['error' => ['message' => 'nicht gefunden']], 404);
        $this->verdrahteTransport();

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/ki');

        $antwort->assertOk();
        $antwort->assertSee('nicht verfuegbar', false);
    }

    public function test_der_healthcheck_zeigt_keinen_api_key(): void
    {
        $this->transport->setDefaultJson(['id' => 'modell']);
        $this->verdrahteTransport();

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/ki');

        $antwort->assertOk();
        $antwort->assertDontSee(self::OPENAI_KEY);
        $antwort->assertDontSee(self::ANTHROPIC_KEY);

        // Auch keine Teilmaskierung: schon ein Praefix des Schluessels darf
        // nicht in der Antwort stehen.
        $antwort->assertDontSee(substr(self::OPENAI_KEY, 0, 12));
        $antwort->assertDontSee(substr(self::ANTHROPIC_KEY, 0, 12));
    }

    public function test_die_fehlende_datenschutzfreigabe_wird_getrennt_ausgewiesen(): void
    {
        config()->set('ai.require_zero_data_retention', true);
        config()->set('ai.data_retention_approved', false);

        $this->transport->setDefaultJson(['id' => 'modell']);
        $this->verdrahteTransport();

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/ki');

        $antwort->assertOk();
        $antwort->assertSee('Datenschutzfreigabe');
        $antwort->assertSee('fehlt');
    }

    public function test_kosten_und_limits_werden_angezeigt(): void
    {
        $this->transport->setDefaultJson(['id' => 'modell']);
        $this->verdrahteTransport();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create();

        AiCall::factory()->create([
            'billing_run_id' => $lauf->getKey(),
            'organization_id' => $lauf->getAttribute('organization_id'),
            'cost_cent' => 1234,
            'created_at' => now(),
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/ki');

        $antwort->assertOk();
        $antwort->assertSee('12,34 EUR');
        $antwort->assertSee('Tageslimit je Nutzer');
    }

    public function test_kosten_je_nutzer_werden_ueber_den_abrechnungslauf_zugeordnet(): void
    {
        $this->transport->setDefaultJson(['id' => 'modell']);
        $this->verdrahteTransport();

        $kunde = $this->kunde();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $kunde['organization']->getKey(),
            'created_by_user_id' => $kunde['user']->getKey(),
        ]);

        AiCall::factory()->create([
            'billing_run_id' => $lauf->getKey(),
            'organization_id' => $kunde['organization']->getKey(),
            'cost_cent' => 500,
            'created_at' => now(),
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/ki');

        $antwort->assertOk();
        $antwort->assertSee('Kosten je Nutzer im laufenden Monat');
        $antwort->assertSee($kunde['user']->getAttribute('email'));
    }

    public function test_ein_ungewoehnlicher_kostenanstieg_wird_gemeldet(): void
    {
        $this->transport->setDefaultJson(['id' => 'modell']);
        $this->verdrahteTransport();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create();

        // Vortage mit geringen Kosten.
        for ($tag = 1; $tag <= 7; $tag++) {
            AiCall::factory()->create([
                'billing_run_id' => $lauf->getKey(),
                'organization_id' => $lauf->getAttribute('organization_id'),
                'cost_cent' => 100,
                'created_at' => now()->subDays($tag)->setTime(10, 0),
            ]);
        }

        // Laufender Tag deutlich darueber.
        AiCall::factory()->create([
            'billing_run_id' => $lauf->getKey(),
            'organization_id' => $lauf->getAttribute('organization_id'),
            'cost_cent' => 5000,
            'created_at' => now(),
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/ki');

        $antwort->assertOk();
        $antwort->assertSee('Ungewöhnlicher Kostenanstieg');
    }

    public function test_promptversionen_werden_ohne_prompttext_angezeigt(): void
    {
        $this->transport->setDefaultJson(['id' => 'modell']);
        $this->verdrahteTransport();

        AiPromptVersion::query()->create([
            'purpose' => AiCallPurpose::EXTRAKTION,
            'version' => '1.2.0',
            'hash' => str_repeat('a', 64),
            'is_active' => true,
            'activated_at' => now(),
            'notes' => 'Testeintrag',
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/ki');

        $antwort->assertOk();
        $antwort->assertSee('1.2.0');
        $antwort->assertSee(str_repeat('a', 12));
        $antwort->assertDontSee(str_repeat('a', 64));
    }
}
