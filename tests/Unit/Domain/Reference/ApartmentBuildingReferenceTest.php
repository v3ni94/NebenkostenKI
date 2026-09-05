<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Reference;

use App\Domain\Calculation\OccupancyKind;
use App\Domain\Calculation\Result\CalculationRunResult;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\StatementCalculator;
use App\Domain\Calculation\TaxBenefitCategory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Reference\ApartmentBuildingReferenceFixture as Fixture;

/**
 * Referenz-Fixture 2: Mehrfamilienhaus mit sechs Einheiten, Mieterwechsel in
 * Wohnung 2, Leerstand im Dezember in Wohnung 4 und zwölf umlagefähigen
 * Kostenarten.
 *
 * Der vollständige Rechenweg ist im Klassenkommentar von
 * Tests\Fixtures\Reference\ApartmentBuildingReferenceFixture offengelegt.
 *
 * Endbeträge: umlagefähige Kosten 25.042,20 EUR, davon 24.689,40 EUR auf
 * sieben Mieterabrechnungen und 352,80 EUR Leerstandsanteil zulasten
 * Eigentümer; ausgeschlossen 2.570,00 EUR.
 */
final class ApartmentBuildingReferenceTest extends TestCase
{
    private CalculationRunResult $result;

    protected function setUp(): void
    {
        $this->result = (new StatementCalculator)->calculate(Fixture::calculationInput());
    }

    #[Test]
    public function es_entstehen_sieben_mieterabrechnungen(): void
    {
        $this->assertSame(7, $this->result->statementCount());
        $this->assertCount(1, $this->result->ownerOverview->vacancyShares);
        $this->assertSame(
            OccupancyKind::VACANCY,
            $this->result->ownerOverview->vacancyShares[0]->kind
        );
        $this->assertSame(31, $this->result->ownerOverview->vacancyShares[0]->days());
    }

    #[Test]
    public function die_umlagefaehigen_kosten_je_mietverhaeltnis_entsprechen_der_handrechnung(): void
    {
        $expected = [
            'mv-1' => Fixture::EXPECTED_MV1_CENT,
            'mv-2' => Fixture::EXPECTED_MV2_CENT,
            'mv-3' => Fixture::EXPECTED_MV3_CENT,
            'mv-4' => Fixture::EXPECTED_MV4_CENT,
            'mv-5' => Fixture::EXPECTED_MV5_CENT,
            'mv-6' => Fixture::EXPECTED_MV6_CENT,
            'mv-7' => Fixture::EXPECTED_MV7_CENT,
        ];

        $sum = 0;

        foreach ($expected as $occupancyKey => $cents) {
            $statement = $this->result->statement($occupancyKey);
            $this->assertNotNull($statement, 'Abrechnung fehlt: '.$occupancyKey);
            $this->assertSame($cents, $statement->allocableTotal->cents, 'Abweichung bei '.$occupancyKey);
            $this->assertTrue($statement->linesMatchAllocableTotal());
            $this->assertCount(12, $statement->lines);
            $sum += $cents;
        }

        $this->assertSame(Fixture::EXPECTED_TENANT_TOTAL_CENT, $sum);
    }

    #[Test]
    public function der_mieterwechsel_in_wohnung_zwei_wird_taggenau_geteilt(): void
    {
        $mv2 = $this->result->statement('mv-2');
        $mv3 = $this->result->statement('mv-3');

        $this->assertNotNull($mv2);
        $this->assertNotNull($mv3);
        $this->assertSame(181, $mv2->usageDays());
        $this->assertSame(184, $mv3->usageDays());

        // Grundsteuer: 62.500 Cent Einheitenanteil, geteilt 30.993 / 31.507
        $this->assertSame(30993, $mv2->line('k-01')?->share->cents);
        $this->assertSame(31507, $mv3->line('k-01')?->share->cents);
        $this->assertSame(62500, 30993 + 31507);

        // Entwässerung: 37.500 Cent, geteilt 18.596 / 18.904
        $this->assertSame(18596, $mv2->line('k-02')?->share->cents);
        $this->assertSame(18904, $mv3->line('k-02')?->share->cents);

        // Verbrauch nach Zwischenablesung: 61,000 und 39,000 m³
        $this->assertSame(42700, $mv2->line('k-11')?->share->cents);
        $this->assertSame(27300, $mv3->line('k-11')?->share->cents);

        // Direktzuordnung Heizung
        $this->assertSame(61000, $mv2->line('k-12')?->share->cents);
        $this->assertSame(48000, $mv3->line('k-12')?->share->cents);
    }

    #[Test]
    public function der_leerstandsmonat_wird_dem_eigentuemer_zugerechnet(): void
    {
        $overview = $this->result->ownerOverview;
        $vacancy = $overview->vacancyShares[0];

        $this->assertSame('W-4', $vacancy->unitKey);
        $this->assertSame(Fixture::EXPECTED_VACANCY_CENT, $vacancy->total->cents);
        $this->assertSame(Fixture::EXPECTED_VACANCY_CENT, $overview->vacancyTotal->cents);

        // Einzelwerte des Leerstands aus der Handrechnung
        $this->assertSame(6625, $vacancy->shareForCategory('GRUNDSTEUER')->cents);
        $this->assertSame(3975, $vacancy->shareForCategory('ENTWAESSERUNG')->cents);
        $this->assertSame(4240, $vacancy->shareForCategory('VERSICHERUNG')->cents);
        $this->assertSame(1590, $vacancy->shareForCategory('ALLGEMEINSTROM')->cents);
        $this->assertSame(662, $vacancy->shareForCategory('HAFTPFLICHT')->cents);
        $this->assertSame(2650, $vacancy->shareForCategory('GARTENPFLEGE')->cents);
        $this->assertSame(795, $vacancy->shareForCategory('STRASSENREINIGUNG')->cents);
        $this->assertSame(2803, $vacancy->shareForCategory('GEBAEUDEREINIGUNG')->cents);
        $this->assertSame(340, $vacancy->shareForCategory('SCHORNSTEINFEGER')->cents);
        $this->assertSame(0, $vacancy->shareForCategory('MUELL')->cents);
        $this->assertSame(2100, $vacancy->shareForCategory('WASSER')->cents);
        $this->assertSame(9500, $vacancy->shareForCategory('HEIZUNG')->cents);
    }

    #[Test]
    public function die_pruefsumme_des_laufs_stimmt_exakt(): void
    {
        $overview = $this->result->ownerOverview;

        $this->assertSame(Fixture::EXPECTED_INCLUDED_TOTAL_CENT, $overview->includedCostTotal->cents);
        $this->assertSame(Fixture::EXPECTED_TENANT_TOTAL_CENT, $overview->allocatedToTenantsTotal->cents);
        $this->assertSame(Fixture::EXPECTED_VACANCY_CENT, $overview->vacancyTotal->cents);
        $this->assertSame(0, $overview->residualTotal->cents);
        $this->assertSame(Fixture::EXPECTED_EXCLUDED_TOTAL_CENT, $overview->excludedCostTotal->cents);
        $this->assertTrue($overview->isBalanced());
        $this->assertSame(0, $overview->checksumDifference()->cents);
        $this->assertSame('27.612,20 EUR', $overview->grossCostTotal()->format());
        $this->assertTrue($this->result->hasFinding(CheckCode::CHECKSUM_BALANCED));
    }

    #[Test]
    public function verwaltungskosten_und_reparatur_werden_ausgeschlossen(): void
    {
        $overview = $this->result->ownerOverview;

        $this->assertCount(2, $overview->excludedCosts);
        $this->assertSame(168000, $overview->excludedCosts[0]->amount->cents);
        $this->assertSame(89000, $overview->excludedCosts[1]->amount->cents);
        $this->assertSame(257000, $overview->excludedCostTotal->cents);
        $this->assertCount(2, $this->result->findingsWithCode(CheckCode::NOT_ALLOCABLE_EXCLUDED));

        foreach ($this->result->statements as $statement) {
            $this->assertNull($statement->line('k-13'));
            $this->assertNull($statement->line('k-14'));
        }
    }

    #[Test]
    public function die_salden_entsprechen_der_handrechnung(): void
    {
        $expected = [
            'mv-1' => -900,
            'mv-2' => 59846,
            'mv-3' => 18984,
            'mv-4' => -3250,
            'mv-5' => 70560,
            'mv-6' => 4800,
            'mv-7' => -5100,
        ];

        $sum = 0;

        foreach ($expected as $occupancyKey => $cents) {
            $statement = $this->result->statement($occupancyKey);
            $this->assertNotNull($statement);
            $this->assertSame($cents, $statement->balance->cents, 'Abweichung im Saldo von '.$occupancyKey);
            $sum += $cents;
        }

        $this->assertSame(Fixture::EXPECTED_BALANCE_SUM_CENT, $sum);
        $this->assertSame(Fixture::EXPECTED_BALANCE_SUM_CENT, $this->result->ownerOverview->tenantBalanceTotal()->cents);
        $this->assertTrue($this->result->statement('mv-1')?->isCredit());
        $this->assertTrue($this->result->statement('mv-2')?->isAdditionalPayment());
    }

    #[Test]
    public function die_uebernahme_der_sollvorauszahlungen_ist_gekennzeichnet(): void
    {
        $mv5 = $this->result->statement('mv-5');

        $this->assertNotNull($mv5);
        $this->assertTrue($mv5->prepaymentAssumedFromTarget);
        $this->assertSame(440000, $mv5->prepaymentActual->cents);
        $this->assertSame(440000, $mv5->prepaymentTarget->cents);
        $this->assertTrue($this->result->hasFinding(CheckCode::PREPAYMENT_ASSUMED_FROM_TARGET));

        foreach (['mv-1', 'mv-2', 'mv-3', 'mv-4', 'mv-6', 'mv-7'] as $occupancyKey) {
            $this->assertFalse($this->result->statement($occupancyKey)?->prepaymentAssumedFromTarget);
        }
    }

    #[Test]
    public function die_personentage_verteilen_die_muellkosten_ohne_doppelte_zeitgewichtung(): void
    {
        // 0,40 EUR je Personentag: mv-1 730 → 292,00; mv-2 543 → 217,20;
        // mv-3 184 → 73,60; mv-5 1.336 → 534,40; Leerstand 0 → 0,00
        $this->assertSame(29200, $this->result->statement('mv-1')?->line('k-10')?->share->cents);
        $this->assertSame(21720, $this->result->statement('mv-2')?->line('k-10')?->share->cents);
        $this->assertSame(7360, $this->result->statement('mv-3')?->line('k-10')?->share->cents);
        $this->assertSame(53440, $this->result->statement('mv-5')?->line('k-10')?->share->cents);
        $this->assertSame(14600, $this->result->statement('mv-6')?->line('k-10')?->share->cents);
        $this->assertTrue($this->result->statement('mv-2')?->line('k-10')?->timeFactor->includedInAllocationKey);
    }

    #[Test]
    public function die_paragraph_35a_lohnanteile_je_mietverhaeltnis_entsprechen_der_handrechnung(): void
    {
        $expectedHousehold = [
            'mv-1' => 13500,
            'mv-2' => 9298,
            'mv-3' => 9452,
            'mv-4' => 18750,
            'mv-5' => 21413,
            'mv-6' => 16500,
            'mv-7' => 24600,
        ];

        $expectedCraftsman = [
            'mv-1' => 4000,
            'mv-2' => 1984,
            'mv-3' => 2016,
            'mv-4' => 4000,
            'mv-5' => 3660,
            'mv-6' => 4000,
            'mv-7' => 4000,
        ];

        foreach ($expectedHousehold as $occupancyKey => $cents) {
            $statement = $this->result->statement($occupancyKey);
            $this->assertNotNull($statement);
            $this->assertSame($cents, $statement->taxBenefitHouseholdServices->cents, 'Lohnanteil '.$occupancyKey);
            $this->assertSame($expectedCraftsman[$occupancyKey], $statement->taxBenefitCraftsmanServices->cents);
            $this->assertCount(1, $statement->linesWithTaxBenefit(TaxBenefitCategory::HOUSEHOLD_SERVICE));
        }

        // Die Summe der Mieteranteile plus Leerstandsanteil ergibt den
        // ausgewiesenen Lohnanteil von 1.155,00 EUR:
        // 13.500 + 9.298 + 9.452 + 18.750 + 21.413 + 16.500 + 24.600 = 113.513
        // zuzüglich Leerstand 1.987 = 115.500 Cent
        $this->assertSame(113513, array_sum($expectedHousehold));
        $this->assertSame(115500, 113513 + 1987);
    }

    #[Test]
    public function jede_kostenzeile_fuehrt_schluessel_zaehler_nenner_und_zeitanteil(): void
    {
        $line = $this->result->statement('mv-5')?->line('k-01');

        $this->assertNotNull($line);
        $this->assertSame('Wohnfläche', $line->allocationKeyLabel);
        $this->assertSame('78,00', $line->numerator);
        $this->assertSame('385,00', $line->denominator);
        $this->assertSame(334, $line->timeFactor->daysUsed);
        $this->assertSame(365, $line->timeFactor->daysInPeriod);
        $this->assertSame(71375, $line->share->cents);
        $this->assertSame(
            'Wohnfläche 78,00 m² von 385,00 m², Zeitanteil 334 von 365 Tagen',
            $line->calculationExplanation()
        );
    }

    #[Test]
    public function der_lauf_ist_finalisierbar(): void
    {
        $this->assertFalse($this->result->blocksFinalization());
        $this->assertSame([], $this->result->findingsWithCode(CheckCode::COVERAGE_GAP));
        $this->assertSame([], $this->result->findingsWithCode(CheckCode::UNALLOCATED_RESIDUAL));
    }
}
