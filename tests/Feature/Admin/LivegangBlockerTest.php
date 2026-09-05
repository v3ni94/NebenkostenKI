<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Application\Admin\LaunchBlockerCheck;
use App\Application\Admin\LaunchBlockerReport;
use App\Application\Payment\OperatorInvoiceBlocker;

/**
 * Livegang-Blocker (Masterprompt 2.1, 6.3, 13.5, 26).
 *
 * Geprueft wird beides: der Blocker wird erkannt, und er verschwindet, sobald
 * die Voraussetzung tatsaechlich erfuellt ist.
 */
final class LivegangBlockerTest extends AdminTestCase
{
    private function bericht(): LaunchBlockerReport
    {
        return app(LaunchBlockerCheck::class)->report();
    }

    public function test_die_uebersicht_und_die_blockerseite_nennen_die_offenen_punkte(): void
    {
        $antwort = $this->actingAs($this->interneKennung())->get('/admin/livegang');

        $antwort->assertOk();
        $antwort->assertSee('Livegang-Blocker');
        $antwort->assertSee('Was fehlt');
        $antwort->assertSee('Folge');
        $antwort->assertSee('Wer stellt es bereit');
    }

    public function test_jeder_blocker_nennt_fehlendes_folge_und_verantwortung(): void
    {
        foreach ($this->bericht()->blockers as $blocker) {
            self::assertNotSame('', $blocker->missing);
            self::assertNotSame('', $blocker->consequence);
            self::assertNotSame('', $blocker->responsible);
            self::assertNotSame('', $blocker->area);
        }
    }

    public function test_fehlende_betreiberstammdaten_werden_erkannt_und_verschwinden_nach_bestaetigung(): void
    {
        self::assertTrue($this->bericht()->has(LaunchBlockerCheck::BETREIBERDATEN));

        $this->bestaetigteBetreiberstammdaten();

        self::assertFalse($this->bericht()->has(LaunchBlockerCheck::BETREIBERDATEN));
    }

    public function test_fehlende_stripe_schluessel_werden_erkannt_und_verschwinden_nach_konfiguration(): void
    {
        self::assertTrue($this->bericht()->has(LaunchBlockerCheck::STRIPE));

        // Frei erfundene Platzhalter, keine echten Schluessel.
        config()->set('services.stripe.key', 'pk_test_platzhalter');
        config()->set('services.stripe.secret', 'sk_test_platzhalter');
        config()->set('services.stripe.webhook_secret', 'whsec_test_platzhalter');

        self::assertFalse($this->bericht()->has(LaunchBlockerCheck::STRIPE));
    }

    public function test_ein_fehlendes_webhook_secret_allein_blockiert_bereits(): void
    {
        config()->set('services.stripe.key', 'pk_test_platzhalter');
        config()->set('services.stripe.secret', 'sk_test_platzhalter');
        config()->set('services.stripe.webhook_secret', null);

        self::assertTrue($this->bericht()->has(LaunchBlockerCheck::STRIPE));
    }

    public function test_fehlende_datenschutzfreigabe_des_ki_providers_wird_erkannt(): void
    {
        config()->set('ai.primary_provider', 'openai');
        config()->set('ai.fallback_enabled', false);
        config()->set('ai.require_zero_data_retention', true);
        config()->set('ai.data_retention_approved', false);

        $bericht = $this->bericht();

        self::assertTrue($bericht->has(LaunchBlockerCheck::KI_DATENSCHUTZFREIGABE));

        config()->set('ai.data_retention_approved', true);

        self::assertFalse($this->bericht()->has(LaunchBlockerCheck::KI_DATENSCHUTZFREIGABE));
    }

    public function test_der_testprovider_ist_produktiv_ein_blocker(): void
    {
        // Die Testumgebung nutzt den Testprovider. Fuer den Livegang ist das
        // ein Blocker, auch wenn der Testprovider hier zulaessig ist.
        config()->set('ai.primary_provider', 'fake');
        config()->set('ai.fallback_enabled', false);
        config()->set('ai.data_retention_approved', true);

        self::assertTrue($this->bericht()->has(LaunchBlockerCheck::KI_DATENSCHUTZFREIGABE));
    }

    public function test_eine_abgeschaltete_ki_anbindung_ist_ein_blocker(): void
    {
        config()->set('ai.bind_document_pipeline', null);
        self::assertFalse($this->bericht()->has(LaunchBlockerCheck::KI_ANBINDUNG));

        config()->set('ai.bind_document_pipeline', false);
        self::assertTrue($this->bericht()->has(LaunchBlockerCheck::KI_ANBINDUNG));
    }

    public function test_ein_tageslimit_von_null_cent_ist_ein_blocker(): void
    {
        config()->set('ai.max_daily_cost_cent_per_user', 0);

        self::assertTrue($this->bericht()->has(LaunchBlockerCheck::KI_TAGESLIMIT));

        config()->set('ai.max_daily_cost_cent_per_user', null);

        self::assertFalse($this->bericht()->has(LaunchBlockerCheck::KI_TAGESLIMIT));
    }

    public function test_ein_tageslimit_ohne_kalkulationsbasis_fuer_die_modelle_des_providers_ist_ein_blocker(): void
    {
        config()->set('ai.primary_provider', 'openai');
        config()->set('ai.fallback_enabled', false);
        config()->set('ai.max_daily_cost_cent_per_user', 500);

        // Die ausgelieferte Basis kennt nur Anthropic-Modelle.
        self::assertTrue($this->bericht()->has(LaunchBlockerCheck::KI_TAGESLIMIT));

        // Testplatzhalter, keine Preisangaben.
        config()->set('ai.cost_basis_us_cent_per_million_tokens', [
            'gpt-5.6-luna' => ['input' => 1, 'output' => 1],
            'gpt-5.6-terra' => ['input' => 1, 'output' => 1],
        ]);

        self::assertFalse($this->bericht()->has(LaunchBlockerCheck::KI_TAGESLIMIT));
    }

    public function test_ein_abgeschalteter_malware_scanner_wird_erkannt_und_verschwindet_nach_konfiguration(): void
    {
        config()->set('smartabrechnen.uploads.malware_scanner.driver', 'disabled');

        self::assertTrue($this->bericht()->has(LaunchBlockerCheck::MALWARE_SCANNER));

        config()->set('smartabrechnen.uploads.malware_scanner.driver', 'clamav');
        config()->set('smartabrechnen.uploads.malware_scanner.endpoint', '/var/run/clamav/clamd.ctl');

        self::assertFalse($this->bericht()->has(LaunchBlockerCheck::MALWARE_SCANNER));
    }

    public function test_platzhalter_rechtstexte_werden_erkannt(): void
    {
        self::assertTrue($this->bericht()->has(LaunchBlockerCheck::RECHTSTEXTE));
    }

    public function test_vorhandenes_hvm_logo_ist_kein_blocker(): void
    {
        self::assertFileExists(public_path('ci/Logo_HVM.jpg'));
        self::assertFalse($this->bericht()->has(LaunchBlockerCheck::CI_ASSETS));
    }

    public function test_fehlende_ci_assets_werden_erkannt(): void
    {
        $leer = sys_get_temp_dir().'/sa-ci-'.uniqid();
        mkdir($leer);

        try {
            $check = new LaunchBlockerCheck(app(OperatorInvoiceBlocker::class), null, $leer);

            self::assertTrue($check->report()->has(LaunchBlockerCheck::CI_ASSETS));
        } finally {
            rmdir($leer);
        }
    }

    public function test_fehlende_aufbewahrungsfristen_werden_erkannt_und_verschwinden_nach_festlegung(): void
    {
        config()->set('smartabrechnen.retention.extracted_data_days', null);
        config()->set('smartabrechnen.retention.generated_pdf_days', null);

        $bericht = $this->bericht();

        self::assertTrue($bericht->has(LaunchBlockerCheck::AUFBEWAHRUNG_EXTRAKTIONSDATEN));
        self::assertTrue($bericht->has(LaunchBlockerCheck::AUFBEWAHRUNG_ERGEBNIS_PDF));

        config()->set('smartabrechnen.retention.extracted_data_days', 3650);
        config()->set('smartabrechnen.retention.generated_pdf_days', 3650);

        $bestaetigt = $this->bericht();

        self::assertFalse($bestaetigt->has(LaunchBlockerCheck::AUFBEWAHRUNG_EXTRAKTIONSDATEN));
        self::assertFalse($bestaetigt->has(LaunchBlockerCheck::AUFBEWAHRUNG_ERGEBNIS_PDF));
    }

    public function test_die_korrekturfrist_ist_kein_livegang_blocker_mehr(): void
    {
        // Die Korrektur nach Zahlung ist zum Start nicht verfuegbar; die
        // Einstellung PRICE_CORRECTION_FREE_DAYS hat keine Wirkung und wird
        // deshalb nicht als offene Entscheidung gefuehrt. Die Klasse kennt
        // dafuer auch keinen Schluessel mehr.
        self::assertFalse($this->bericht()->has('korrekturfrist'));
        self::assertFalse(defined(LaunchBlockerCheck::class.'::KORREKTURFRIST'));
    }

    public function test_ein_preis_ausserhalb_des_korridors_ist_ein_blocker(): void
    {
        config()->set('smartabrechnen.pricing.per_statement_gross_cent', 2490);
        self::assertFalse($this->bericht()->has(LaunchBlockerCheck::PREISKORRIDOR));

        // Cent statt Euro in der .env: 24.900,00 EUR je Abrechnung.
        config()->set('smartabrechnen.pricing.per_statement_gross_cent', 2490000);

        $bericht = $this->bericht();

        self::assertTrue($bericht->has(LaunchBlockerCheck::PREISKORRIDOR));

        foreach ($bericht->blockers as $blocker) {
            if ($blocker->key === LaunchBlockerCheck::PREISKORRIDOR) {
                self::assertStringContainsString('24.900,00 EUR', $blocker->missing);
                self::assertStringContainsString('20,00 EUR', $blocker->missing);
                self::assertStringContainsString('30,00 EUR', $blocker->missing);
            }
        }

        // Unterhalb des Korridors ebenfalls.
        config()->set('smartabrechnen.pricing.per_statement_gross_cent', 1999);
        self::assertTrue($this->bericht()->has(LaunchBlockerCheck::PREISKORRIDOR));

        // Die Korridorgrenzen selbst sind zulaessig.
        config()->set('smartabrechnen.pricing.per_statement_gross_cent', 3000);
        self::assertFalse($this->bericht()->has(LaunchBlockerCheck::PREISKORRIDOR));
    }

    public function test_die_uebersicht_zeigt_die_anzahl_der_blocker(): void
    {
        $antwort = $this->actingAs($this->interneKennung())->get('/admin');

        $antwort->assertOk();
        $antwort->assertSee('Offene Punkte');
    }
}
