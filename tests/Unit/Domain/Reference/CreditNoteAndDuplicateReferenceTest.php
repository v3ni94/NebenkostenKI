<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Reference;

use App\Domain\Calculation\Check\DuplicateCostDetector;
use App\Domain\Calculation\Check\PreviousYearComparator;
use App\Domain\Calculation\Result\CalculationRunResult;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Calculation\StatementCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Reference\CreditNoteAndDuplicateReferenceFixture as Fixture;

/**
 * Referenz-Fixture 3: Rechnung, Gutschrift, erkannte Dublette und
 * Vorjahresabweichung.
 *
 * Der vollständige Rechenweg ist im Klassenkommentar von
 * Tests\Fixtures\Reference\CreditNoteAndDuplicateReferenceFixture offengelegt.
 *
 * Endbeträge: einbezogene Kosten 3.679,00 EUR, mv-a 2.284,38 EUR
 * (Nachzahlung 184,38 EUR), mv-b 1.394,62 EUR (Guthaben 45,38 EUR),
 * ausgeschlossene Dublette 640,00 EUR.
 */
final class CreditNoteAndDuplicateReferenceTest extends TestCase
{
    private CalculationRunResult $result;

    protected function setUp(): void
    {
        $this->result = (new StatementCalculator)->calculate(Fixture::calculationInput());
    }

    #[Test]
    public function die_gutschrift_wird_mit_demselben_schluessel_negativ_verteilt(): void
    {
        $mva = $this->result->statement('mv-a');
        $mvb = $this->result->statement('mv-b');

        $this->assertNotNull($mva);
        $this->assertNotNull($mvb);

        // Rechnung 1.200,00 EUR
        $this->assertSame(75000, $mva->line('k-01')?->share->cents);
        $this->assertSame(45000, $mvb->line('k-01')?->share->cents);

        // Gutschrift -181,00 EUR: exakt -11.312,50 und -6.787,50 Cent,
        // Gleichstand der Reste, mv-a erhält den Cent.
        $creditA = $mva->line('k-02');
        $creditB = $mvb->line('k-02');
        $this->assertNotNull($creditA);
        $this->assertNotNull($creditB);
        $this->assertSame(-11312, $creditA->share->cents);
        $this->assertSame(-6788, $creditB->share->cents);
        $this->assertSame(-18100, -11312 + -6788);
        $this->assertTrue($creditA->isCreditNote());
        $this->assertSame(1, $creditA->roundingAdjustmentCent);
        $this->assertSame(0, $creditB->roundingAdjustmentCent);
        $this->assertTrue($this->result->hasFinding(CheckCode::CREDIT_NOTE_APPLIED));
    }

    #[Test]
    public function die_doppelt_erfasste_rechnung_wird_nicht_umgelegt(): void
    {
        $overview = $this->result->ownerOverview;

        $this->assertCount(1, $overview->excludedCosts);
        $this->assertSame('k-06', $overview->excludedCosts[0]->costItemKey);
        $this->assertSame(Fixture::EXPECTED_EXCLUDED_TOTAL_CENT, $overview->excludedCostTotal->cents);
        $this->assertNull($this->result->statement('mv-a')?->line('k-06'));
        $this->assertNull($this->result->statement('mv-b')?->line('k-06'));
        $this->assertCount(1, $this->result->findingsWithCode(CheckCode::NOT_ALLOCABLE_EXCLUDED));
    }

    #[Test]
    public function die_dublettenpruefung_meldet_genau_einen_verdacht(): void
    {
        $findings = (new DuplicateCostDetector)->detect(Fixture::invoiceReferences());

        $this->assertCount(1, $findings);
        $this->assertSame(CheckCode::DUPLICATE_COST_SUSPECTED, $findings[0]->code);
        $this->assertSame('k-05', $findings[0]->context['costItemKey']);
        $this->assertSame('k-06', $findings[0]->context['duplicateOf']);
        $this->assertStringContainsString('gleiche Rechnungsnummer', $findings[0]->message);
        $this->assertStringContainsString('640,00 EUR', $findings[0]->message);
    }

    #[Test]
    public function der_vorjahresvergleich_meldet_zwei_abweichungen_eine_fehlende_und_eine_neue_kategorie(): void
    {
        $findings = (new PreviousYearComparator)->compare(
            Fixture::costItems(),
            Fixture::previousYear()
        );

        $codes = array_map(static fn (CheckFinding $finding): string => $finding->code->value, $findings);

        $this->assertCount(4, $findings);
        $this->assertSame(2, array_count_values($codes)[CheckCode::PREVIOUS_YEAR_DEVIATION->value]);
        $this->assertSame(1, array_count_values($codes)[CheckCode::PREVIOUS_YEAR_CATEGORY_MISSING->value]);
        $this->assertSame(1, array_count_values($codes)[CheckCode::PREVIOUS_YEAR_CATEGORY_NEW->value]);

        $messages = implode(' | ', array_map(
            static fn (CheckFinding $finding): string => $finding->message,
            $findings
        ));

        // Gartenpflege netto 1.019,00 EUR gegenüber 760,00 EUR: 34,1 Prozent
        $this->assertStringContainsString('1.019,00 EUR', $messages);
        $this->assertStringContainsString('34,1 Prozent', $messages);
        // Versicherung 1.280,00 EUR gegenüber 620,00 EUR: 106,5 Prozent
        $this->assertStringContainsString('1.280,00 EUR', $messages);
        $this->assertStringContainsString('106,5 Prozent', $messages);
        $this->assertStringContainsString('Allgemeinstrom', $messages);
        $this->assertStringContainsString('Schornsteinreinigung', $messages);
    }

    #[Test]
    public function die_endbetraege_entsprechen_der_handrechnung(): void
    {
        $mva = $this->result->statement('mv-a');
        $mvb = $this->result->statement('mv-b');

        $this->assertNotNull($mva);
        $this->assertNotNull($mvb);

        $this->assertSame(Fixture::EXPECTED_MVA_CENT, $mva->allocableTotal->cents);
        $this->assertSame(Fixture::EXPECTED_MVB_CENT, $mvb->allocableTotal->cents);
        $this->assertSame('2.284,38 EUR', $mva->allocableTotal->format());
        $this->assertSame('1.394,62 EUR', $mvb->allocableTotal->format());

        $this->assertSame(Fixture::EXPECTED_MVA_BALANCE_CENT, $mva->balance->cents);
        $this->assertSame(Fixture::EXPECTED_MVB_BALANCE_CENT, $mvb->balance->cents);
        $this->assertSame('184,38 EUR', $mva->additionalPayment()->format());
        $this->assertSame('45,38 EUR', $mvb->credit()->format());

        $this->assertTrue($mva->linesMatchAllocableTotal());
        $this->assertTrue($mvb->linesMatchAllocableTotal());
        $this->assertCount(6, $mva->lines);
    }

    #[Test]
    public function die_pruefsumme_des_laufs_stimmt_exakt(): void
    {
        $overview = $this->result->ownerOverview;

        $this->assertSame(Fixture::EXPECTED_INCLUDED_TOTAL_CENT, $overview->includedCostTotal->cents);
        $this->assertSame(Fixture::EXPECTED_INCLUDED_TOTAL_CENT, $overview->allocatedToTenantsTotal->cents);
        $this->assertSame(0, $overview->vacancyTotal->cents);
        $this->assertSame(0, $overview->residualTotal->cents);
        $this->assertTrue($overview->isBalanced());
        $this->assertSame(
            Fixture::EXPECTED_MVA_CENT + Fixture::EXPECTED_MVB_CENT,
            $overview->allocatedToTenantsTotal->cents
        );
        $this->assertSame('4.319,00 EUR', $overview->grossCostTotal()->format());
        $this->assertFalse($this->result->blocksFinalization());
    }
}
