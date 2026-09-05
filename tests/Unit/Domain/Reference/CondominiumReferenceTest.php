<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Reference;

use App\Domain\Calculation\Heating\Co2AllocationStatus;
use App\Domain\Calculation\Heating\ExternalHeatingReconciler;
use App\Domain\Calculation\Heating\ExternalHeatingStatementInput;
use App\Domain\Calculation\Result\CalculationRunResult;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckSeverity;
use App\Domain\Calculation\StatementCalculator;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Calculation\Weg\HausgeldCostExtractor;
use App\Domain\Calculation\Weg\PropertyTaxMerger;
use App\Domain\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Reference\CondominiumReferenceFixture as Fixture;

/**
 * Referenz-Fixture 1: Eigentumswohnung mit WEG-Hausgeldabrechnung,
 * separater Grundsteuer und externer Heizkostenabrechnung.
 *
 * Der vollständige Rechenweg und alle handgeprüften Zwischenergebnisse sind
 * im Klassenkommentar von Tests\Fixtures\Reference\CondominiumReferenceFixture
 * dokumentiert.
 *
 * Endergebnis: umlagefähige Kosten 3.346,35 EUR, Vorauszahlungen 3.000,00 EUR,
 * Nachzahlung 346,35 EUR.
 */
final class CondominiumReferenceTest extends TestCase
{
    #[Test]
    public function die_hausgeldabrechnung_liefert_1812_65_euro_umlagefaehige_kosten(): void
    {
        $extraction = (new HausgeldCostExtractor)->extract(
            Fixture::hausgeldStatement(),
            Fixture::ALLOCATION_KEY_UNIT,
            true
        );

        $this->assertSame(Fixture::EXPECTED_WEG_ACCEPTED_CENT, $extraction->acceptedTotal->cents);
        $this->assertSame(Fixture::EXPECTED_WEG_EXCLUDED_CENT, $extraction->excludedTotal->cents);
        $this->assertCount(7, $extraction->acceptedCostItems);
        $this->assertCount(6, $extraction->excludedPositions);
        $this->assertFalse($extraction->containsCategory('HEIZUNG'));
        $this->assertFalse($extraction->blocksFinalization());

        $checksum = array_values(array_filter(
            $extraction->findings,
            static fn ($finding): bool => $finding->code === CheckCode::WEG_UNIT_SHARE_CHECKSUM
        ));
        $this->assertSame(CheckSeverity::PASSED, $checksum[0]->severity);
    }

    #[Test]
    public function die_grundsteuer_wird_mit_385_20_euro_ergaenzt(): void
    {
        $merge = (new PropertyTaxMerger)->merge(
            Fixture::propertyTax(),
            Fixture::hausgeldStatement(),
            Fixture::billingPeriod(),
            Fixture::ALLOCATION_KEY_UNIT
        );

        $this->assertTrue($merge->added);
        $this->assertFalse($merge->possibleDuplicate);
        $this->assertSame(Fixture::EXPECTED_PROPERTY_TAX_CENT, $merge->costItem?->totalAmount->cents);
    }

    #[Test]
    public function die_externe_heizkostenabrechnung_besteht_die_pruefsumme(): void
    {
        $reconciliation = (new ExternalHeatingReconciler)->reconcile(
            Fixture::heatingStatement(),
            Fixture::heatingTolerance()
        );

        $this->assertTrue($reconciliation->withinTolerance);
        $this->assertSame(0, $reconciliation->difference->cents);
        $this->assertSame(Fixture::EXPECTED_HEATING_CENT, $reconciliation->sumOfParticipantAmounts->cents);
        $this->assertFalse($reconciliation->blocksFinalization());
        $this->assertNotNull($reconciliation->allocationKey());
    }

    #[Test]
    public function das_endergebnis_ist_eine_nachzahlung_von_346_35_euro(): void
    {
        $result = $this->calculateRun();
        $statement = $result->statement(Fixture::TENANCY_KEY);

        $this->assertNotNull($statement);
        $this->assertCount(9, $statement->lines);
        $this->assertSame(Fixture::EXPECTED_ALLOCABLE_TOTAL_CENT, $statement->allocableTotal->cents);
        $this->assertSame(Fixture::EXPECTED_PREPAYMENT_CENT, $statement->prepaymentActual->cents);
        $this->assertSame(Fixture::EXPECTED_PREPAYMENT_CENT, $statement->prepaymentTarget->cents);
        $this->assertFalse($statement->prepaymentAssumedFromTarget);
        $this->assertSame(Fixture::EXPECTED_BALANCE_CENT, $statement->balance->cents);
        $this->assertTrue($statement->isAdditionalPayment());
        $this->assertSame('346,35 EUR', $statement->additionalPayment()->format());
        $this->assertTrue($statement->linesMatchAllocableTotal());
        $this->assertSame(0, $statement->totalRoundingAdjustmentCent());
    }

    #[Test]
    public function die_einzelnen_kostenzeilen_entsprechen_den_handgepruefen_betraegen(): void
    {
        $statement = $this->calculateRun()->statement(Fixture::TENANCY_KEY);
        $this->assertNotNull($statement);

        $expected = [
            'weg-p-01' => 41280,
            'weg-p-02' => 18640,
            'weg-p-03' => 24315,
            'weg-p-04' => 9630,
            'weg-p-05' => 31000,
            'weg-p-06' => 48000,
            'weg-p-07' => 8400,
            'grundsteuer-W-12' => 38520,
            'heizkosten-ista-2025' => 114850,
        ];

        $sum = 0;

        foreach ($expected as $costItemKey => $cents) {
            $line = $statement->line($costItemKey);
            $this->assertNotNull($line, 'Zeile fehlt: '.$costItemKey);
            $this->assertSame($cents, $line->share->cents, 'Abweichung in Zeile '.$costItemKey);
            $this->assertSame(0, $line->roundingAdjustmentCent);
            $sum += $cents;
        }

        $this->assertSame(Fixture::EXPECTED_ALLOCABLE_TOTAL_CENT, $sum);
    }

    #[Test]
    public function der_paragraph_35a_ausweis_trennt_dienstleistung_und_handwerkerleistung(): void
    {
        $statement = $this->calculateRun()->statement(Fixture::TENANCY_KEY);

        $this->assertNotNull($statement);
        $this->assertSame(
            Fixture::EXPECTED_TAX_BENEFIT_HOUSEHOLD_CENT,
            $statement->taxBenefitHouseholdServices->cents
        );
        $this->assertSame(
            Fixture::EXPECTED_TAX_BENEFIT_CRAFTSMAN_CENT,
            $statement->taxBenefitCraftsmanServices->cents
        );
        $this->assertSame(29400, $statement->taxBenefitTotal()->cents);

        $hauswart = $statement->line('weg-p-06');
        $this->assertNotNull($hauswart);
        $this->assertNull($hauswart->taxBenefitLaborShare);
        $this->assertTrue($hauswart->hasUndisclosedLaborShare());
        $this->assertSame(TaxBenefitCategory::HOUSEHOLD_SERVICE, $hauswart->taxBenefitCategory);
    }

    #[Test]
    public function die_eigentuemeruebersicht_weist_keine_leerstands_und_restanteile_aus(): void
    {
        $result = $this->calculateRun();
        $overview = $result->ownerOverview;

        $this->assertSame(Fixture::EXPECTED_ALLOCABLE_TOTAL_CENT, $overview->includedCostTotal->cents);
        $this->assertSame(Fixture::EXPECTED_ALLOCABLE_TOTAL_CENT, $overview->allocatedToTenantsTotal->cents);
        $this->assertSame(0, $overview->vacancyTotal->cents);
        $this->assertSame(0, $overview->residualTotal->cents);
        $this->assertSame(0, $overview->excludedCostTotal->cents);
        $this->assertTrue($overview->isBalanced());
        $this->assertFalse($result->blocksFinalization());
        $this->assertSame(1, $result->statementCount());
    }

    /**
     * Vollständiger Lauf: Hausgeldabrechnung, Grundsteuer, externe
     * Heizkostenabrechnung und Berechnungsengine.
     */
    private function calculateRun(): CalculationRunResult
    {
        $extraction = (new HausgeldCostExtractor)->extract(
            Fixture::hausgeldStatement(),
            Fixture::ALLOCATION_KEY_UNIT,
            true
        );

        $merge = (new PropertyTaxMerger)->merge(
            Fixture::propertyTax(),
            Fixture::hausgeldStatement(),
            Fixture::billingPeriod(),
            Fixture::ALLOCATION_KEY_UNIT
        );

        $reconciliation = (new ExternalHeatingReconciler)->reconcile(
            Fixture::heatingStatement(),
            Fixture::heatingTolerance()
        );

        $heatingKey = $reconciliation->allocationKey();
        $this->assertNotNull($heatingKey);

        $costItems = array_merge(
            $extraction->acceptedCostItems,
            $merge->costItems(),
            [Fixture::heatingCostItem()]
        );

        return (new StatementCalculator)->calculate(Fixture::calculationInput(
            array_values($costItems),
            [
                Fixture::ALLOCATION_KEY_UNIT => Fixture::unitAllocationKey(),
                Fixture::ALLOCATION_KEY_HEATING => $heatingKey,
            ]
        ));
    }

    #[Test]
    public function die_pruefsumme_der_heizkosten_bleibt_bei_abweichung_ausserhalb_der_toleranz_ein_blocker(): void
    {
        // Gegenprobe: derselbe Fall mit einem Einzelbetrag von 1.100,00 EUR
        // gegenüber dem Gesamtbetrag 1.148,50 EUR ergibt eine Abweichung von
        // 48,50 EUR und blockiert die Finalisierung.
        $statement = new ExternalHeatingStatementInput(
            'ista',
            Fixture::billingPeriod(),
            Money::fromEuros('1148.50'),
            [Fixture::TENANCY_KEY => Money::fromEuros('1100.00')],
            Co2AllocationStatus::INCLUDED
        );

        $reconciliation = (new ExternalHeatingReconciler)->reconcile($statement, Fixture::heatingTolerance());

        $this->assertFalse($reconciliation->withinTolerance);
        $this->assertSame(-4850, $reconciliation->difference->cents);
        $this->assertTrue($reconciliation->blocksFinalization());
        $this->assertNull($reconciliation->allocationKey());
    }
}
