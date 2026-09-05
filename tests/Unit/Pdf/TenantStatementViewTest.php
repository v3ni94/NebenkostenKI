<?php

declare(strict_types=1);

namespace Tests\Unit\Pdf;

use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;
use Tests\Feature\Pdf\PdfFixtures;
use Tests\TestCase;

/**
 * Die Darstellungsschicht gruppiert nur, sie rechnet nicht: alle Beträge
 * stammen unverändert aus dem UnitStatementResult.
 */
class TenantStatementViewTest extends TestCase
{
    public function test_betreff_folgt_der_vorgabe(): void
    {
        $view = PdfFixtures::statementView();

        $this->assertSame('Betriebskostenabrechnung 01.01.2025 bis 31.12.2025', $view->subjectLine());
    }

    public function test_heizkosten_werden_in_einem_eigenen_block_ausgewiesen(): void
    {
        $view = PdfFixtures::statementView();

        $this->assertTrue($view->hasHeatingBlock());
        $this->assertCount(1, $view->heatingLines());
        $this->assertCount(5, $view->regularLines());
        $this->assertSame(PdfFixtures::HEATING_CATEGORY, $view->heatingLines()[0]->categoryKey);
    }

    public function test_zwischensummen_entsprechen_der_summe_der_zeilenanteile(): void
    {
        $view = PdfFixtures::statementView();

        $summe = $view->subtotalWithoutHeating()->plus($view->heatingSubtotal());

        $this->assertTrue($summe->equals($view->result->allocableTotal));
    }

    public function test_fehlende_zwischenablesung_und_ersatzverteilung_werden_gekennzeichnet(): void
    {
        $lines = PdfFixtures::defaultLines();
        $lines[] = PdfFixtures::substituteDistributionLine();

        $view = PdfFixtures::statementView(PdfFixtures::statementResult($lines));

        $this->assertTrue($view->hasSubstituteDistribution());
        $this->assertStringContainsString(
            'keine Zwischenablesung',
            implode(' ', $view->notices())
        );
        $this->assertStringContainsString('Ersatzverteilung', implode(' ', $view->notices()));
    }

    public function test_uebernommene_sollvorauszahlungen_werden_gekennzeichnet(): void
    {
        $view = PdfFixtures::statementView(
            PdfFixtures::statementResult(null, [], [], 120000, true)
        );

        $this->assertStringContainsString('Sollvorauszahlungen', implode(' ', $view->notices()));
    }

    public function test_annahmen_aus_dem_ergebnisobjekt_werden_uebernommen(): void
    {
        $view = PdfFixtures::statementView(
            PdfFixtures::statementResult(null, ['Die Wohnfläche wurde aus dem Mietvertrag übernommen.'])
        );

        $this->assertContains('Die Wohnfläche wurde aus dem Mietvertrag übernommen.', $view->notices());
    }

    public function test_verteilerschluessel_werden_fuer_die_anlage_gruppiert(): void
    {
        $schluessel = PdfFixtures::statementView()->allocationKeyExplanations();

        $labels = array_column($schluessel, 'label');

        $this->assertSame(['Wohnfläche', 'Personentage', 'Verbrauch und Grundkosten'], $labels);
        $this->assertContains('Grundsteuer', $schluessel[0]['categories']);
        $this->assertContains('Hausmeisterdienst', $schluessel[0]['categories']);
    }

    public function test_begunstigte_zeilen_werden_getrennt_ausgewiesen(): void
    {
        $view = PdfFixtures::statementView();

        $this->assertCount(2, $view->taxBenefitLines(TaxBenefitCategory::HOUSEHOLD_SERVICE));
        $this->assertCount(1, $view->taxBenefitLines(TaxBenefitCategory::CRAFTSMAN_SERVICE));
        $this->assertTrue($view->hasTaxBenefitContent());
    }

    public function test_nicht_ausgewiesener_lohnanteil_bleibt_ohne_betrag(): void
    {
        $view = PdfFixtures::statementView();

        $ohneAusweis = array_values(array_filter(
            $view->taxBenefitLines(TaxBenefitCategory::HOUSEHOLD_SERVICE),
            static fn ($line): bool => $line->hasUndisclosedLaborShare()
        ));

        $this->assertCount(1, $ohneAusweis);
        $this->assertNull($ohneAusweis[0]->taxBenefitLaborShare);
        $this->assertTrue($view->result->taxBenefitHouseholdServices->equals(Money::fromCents(29000)));
    }

    public function test_bankverbindung_kann_abgeschaltet_werden(): void
    {
        $this->assertNotNull(PdfFixtures::statementView()->bankAccount());
        $this->assertNull(PdfFixtures::statementView(null, false, false)->bankAccount());
    }
}
