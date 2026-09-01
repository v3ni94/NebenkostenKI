<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Application\Admin\LaunchBlockerCheck;

/**
 * Livegang-Blocker (Masterprompt 2.1, 6.3, 13.5, 26).
 *
 * Geprueft wird beides: der Blocker wird erkannt, und er verschwindet, sobald
 * die Voraussetzung tatsaechlich erfuellt ist.
 */
final class LivegangBlockerTest extends AdminTestCase
{
    private function bericht(): \App\Application\Admin\LaunchBlockerReport
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

    public function test_fehlende_ci_assets_werden_erkannt(): void
    {
        self::assertTrue($this->bericht()->has(LaunchBlockerCheck::CI_ASSETS));
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

    public function test_eine_nicht_entschiedene_korrekturfrist_wird_erkannt(): void
    {
        self::assertTrue($this->bericht()->has(LaunchBlockerCheck::KORREKTURFRIST));

        $_SERVER['PRICE_CORRECTION_FREE_DAYS'] = '14';

        try {
            self::assertFalse($this->bericht()->has(LaunchBlockerCheck::KORREKTURFRIST));
        } finally {
            unset($_SERVER['PRICE_CORRECTION_FREE_DAYS']);
        }
    }

    public function test_die_uebersicht_zeigt_die_anzahl_der_blocker(): void
    {
        $antwort = $this->actingAs($this->interneKennung())->get('/admin');

        $antwort->assertOk();
        $antwort->assertSee('Offene Punkte');
    }
}
