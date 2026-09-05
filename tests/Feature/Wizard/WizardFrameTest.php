<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Application\BillingRun\PortalStatusCategory;
use App\Application\Calculation\Dto\PriceEstimate;
use App\Application\Calculation\EstimatePrice;
use App\Application\Wizard\WizardProgress;
use App\Application\Wizard\WizardStep;
use App\Models\Prepayment;
use App\Models\Tenancy;
use App\Models\Unit;
use Tests\Feature\Calculation\CalculationTestCase;

/**
 * Rahmen des geführten Ablaufs: Fortschrittsleiste, Schrittpersistenz und
 * unverbindliche Preisschätzung.
 */
final class WizardFrameTest extends CalculationTestCase
{
    public function test_die_fortschrittsleiste_umfasst_alle_zwoelf_schritte(): void
    {
        $szenario = $this->szenario();

        $leiste = app(WizardProgress::class)->bar($szenario['billingRun']);

        // Zwoelf Schritte wie auf der Website: 1 bis 10 im Assistenten, 11 Zahlung, 12 Finalisierung.
        self::assertCount(12, $leiste);
        self::assertSame(1, $leiste[0]->nummer());
        self::assertSame('Vorschau und Bestätigung', $leiste[9]->label());
        self::assertSame('Zahlung', $leiste[10]->label());
        self::assertSame('Finalisierung', $leiste[11]->label());
        self::assertSame(PortalStatusCategory::FEHLT_NOCH, $leiste[10]->kategorie);
        self::assertFalse($leiste[10]->erreichbar);
    }

    public function test_der_wiedereinstiegshinweis_entfaellt_auf_der_seite_des_gespeicherten_schritts(): void
    {
        $szenario = $this->szenario();
        $fortschritt = app(WizardProgress::class);

        $fortschritt->remember($szenario['billingRun'], WizardStep::VERTEILERSCHLUESSEL);

        self::assertNull($fortschritt->resumeHint($szenario['billingRun'], WizardStep::VERTEILERSCHLUESSEL));
        self::assertStringContainsString('Schritt 8 von 12', (string) $fortschritt->resumeHint($szenario['billingRun'], WizardStep::VORAUSZAHLUNGEN));
    }

    public function test_die_statussprache_ist_verbindlich(): void
    {
        $szenario = $this->szenario();

        $leiste = app(WizardProgress::class)->bar($szenario['billingRun']);
        $kategorien = array_map(static fn ($station): string => $station->kategorie, $leiste);

        foreach ($kategorien as $kategorie) {
            self::assertContains($kategorie, PortalStatusCategory::all());
        }
    }

    public function test_offene_vorauszahlungen_erscheinen_als_fehlt_noch(): void
    {
        $szenario = $this->szenario();

        Prepayment::query()->where('billing_run_id', $szenario['billingRun']->getKey())->delete();

        $leiste = app(WizardProgress::class)->bar($szenario['billingRun']->refresh());

        self::assertSame(PortalStatusCategory::FEHLT_NOCH, $leiste[6]->kategorie);
        self::assertSame('Fehlt noch', $leiste[6]->kategorie);
    }

    public function test_vollstaendige_vorauszahlungen_erscheinen_als_erledigt(): void
    {
        $szenario = $this->szenario();

        $leiste = app(WizardProgress::class)->bar($szenario['billingRun']);

        self::assertSame(PortalStatusCategory::ERLEDIGT, $leiste[6]->kategorie);
    }

    public function test_fehlende_schluesselwerte_erscheinen_als_blockiert(): void
    {
        $szenario = $this->szenario();

        Unit::query()
            ->whereKey($szenario['units'][1]->getKey())
            ->update(['living_area_sqm' => null]);

        $leiste = app(WizardProgress::class)->bar($szenario['billingRun']->refresh());

        self::assertSame(PortalStatusCategory::BLOCKIERT, $leiste[7]->kategorie);
        self::assertTrue($leiste[7]->blockiert());
    }

    public function test_der_erreichte_schritt_wird_gespeichert_und_nicht_zurueckgesetzt(): void
    {
        $szenario = $this->szenario();
        $fortschritt = app(WizardProgress::class);

        $fortschritt->remember($szenario['billingRun'], WizardStep::PRUEFBERICHT);

        self::assertSame(9, $szenario['billingRun']->refresh()->wizard_step);

        // Die Zurück-Navigation setzt den Fortschritt nicht zurück.
        $fortschritt->remember($szenario['billingRun'], WizardStep::VORAUSZAHLUNGEN);

        self::assertSame(9, $szenario['billingRun']->refresh()->wizard_step);
    }

    public function test_der_ablauf_ist_unterbrechbar_und_ohne_datenverlust_fortsetzbar(): void
    {
        $szenario = $this->szenario();

        // Der Nutzer speichert Schritt 7 und verlässt die Anwendung.
        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['zeilen' => [(string) $szenario['tenancies'][0]->getKey() => ['ist' => '1.234,56']]]
        )->assertRedirect();

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorauszahlungen.weiter', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertRedirect();

        self::assertSame(8, $szenario['billingRun']->refresh()->wizard_step);

        // Später kehrt der Nutzer zurück und findet seine Angaben vor.
        $antwort = $this->actingAs($szenario['user'])->get(
            route('portal.wizard.vorauszahlungen', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('1.234,56');
        // Wiedereinstieg: die Seite zeigt Schritt 7, gespeichert ist Schritt 8 von zwoelf.
        $antwort->assertSee('Schritt 7 von 12');
        $antwort->assertSee('Ihr zuletzt gespeicherter Stand ist Schritt 8 von 12');

        // Der Fortschritt bleibt bei Schritt 8, obwohl Schritt 7 erneut
        // aufgerufen wurde.
        self::assertSame(8, $szenario['billingRun']->refresh()->wizard_step);
    }

    public function test_der_rahmen_verlinkt_die_schritte_1_bis_6_der_anderen_bausteine(): void
    {
        $szenario = $this->szenario();

        app(WizardProgress::class)->remember($szenario['billingRun'], WizardStep::VORSCHAU);

        $antwort = $this->actingAs($szenario['user'])->get(
            route('portal.wizard.vorauszahlungen', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee(route('portal.uploads.index', ['billingRun' => $szenario['billingRun']->getKey()]), false);
        $antwort->assertSee(route('portal.pruefung.analyse', ['billingRun' => $szenario['billingRun']->getKey()]), false);
        $antwort->assertSee(route('portal.pruefung.kosten', ['billingRun' => $szenario['billingRun']->getKey()]), false);
        $antwort->assertSee('Kostenprüfung');
    }

    public function test_die_preisschaetzung_folgt_der_anzahl_der_abrechnungen(): void
    {
        $szenario = $this->szenario();

        config()->set('smartabrechnen.pricing.per_statement_gross_cent', 2490);
        config()->set('smartabrechnen.pricing.base_gross_cent', 0);

        $schaetzung = app(EstimatePrice::class)->forBillingRun($szenario['billingRun']);

        self::assertSame(2, $schaetzung->statementCount);
        self::assertSame(4980, $schaetzung->totalGross->cents);
        self::assertSame('49,80 EUR', $schaetzung->totalGross->format());
    }

    public function test_die_preisschaetzung_beruecksichtigt_den_grundpreis(): void
    {
        config()->set('smartabrechnen.pricing.per_statement_gross_cent', 2490);
        config()->set('smartabrechnen.pricing.base_gross_cent', 500);

        $schaetzung = app(EstimatePrice::class)->forCount(3);

        self::assertSame(7970, $schaetzung->totalGross->cents);
        self::assertStringContainsString('3 Mieterabrechnungen × 24,90 EUR', $schaetzung->explanation());
        self::assertStringContainsString('Grundpreis 5,00 EUR', $schaetzung->explanation());
    }

    public function test_die_preisschaetzung_ist_ausdruecklich_als_unverbindlich_gekennzeichnet(): void
    {
        $schaetzung = app(EstimatePrice::class)->forCount(1);

        self::assertSame(PriceEstimate::HINT, $schaetzung->hint());
        self::assertStringContainsString('Unverbindliche Schätzung', $schaetzung->hint());
        self::assertStringContainsString('vor der Zahlung', $schaetzung->hint());
    }

    public function test_ein_mieterwechsel_erhoeht_die_geschaetzte_anzahl_der_abrechnungen(): void
    {
        $szenario = $this->szenario();

        Tenancy::query()
            ->whereKey($szenario['tenancies'][0]->getKey())
            ->update(['ends_on' => '2025-06-30']);

        Tenancy::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'property_id' => $szenario['property']->getKey(),
            'unit_id' => $szenario['units'][0]->getKey(),
            'starts_on' => '2025-07-01',
            'ends_on' => null,
        ]);

        $szenario['billingRun']->forceFill(['statement_count' => 0])->save();

        self::assertSame(3, app(EstimatePrice::class)->expectedStatementCount($szenario['billingRun']->refresh()));
    }
}
