<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Heating;

use App\Domain\Calculation\Heating\Co2AllocationStatus;
use App\Domain\Calculation\Heating\ExternalHeatingReconciler;
use App\Domain\Calculation\Heating\ExternalHeatingStatementInput;
use App\Domain\Calculation\Heating\HeatingSupplyType;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckSeverity;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fall A: externe Heizkostenabrechnung mit Prüfsumme gegen den Gesamtbetrag
 * (Pflichtenheft Abschnitt 12.3 und 7.4).
 */
final class ExternalHeatingReconcilerTest extends TestCase
{
    private ExternalHeatingReconciler $reconciler;

    protected function setUp(): void
    {
        $this->reconciler = new ExternalHeatingReconciler;
    }

    #[Test]
    public function pruefsumme_innerhalb_der_toleranz_gibt_die_direktzuordnung_frei(): void
    {
        // Einzelbeträge 610,00 + 480,00 = 1.090,00 EUR, Gesamtbetrag 1.090,00 EUR.
        $result = $this->reconciler->reconcile(
            $this->statement('1090.00', ['mv-1' => '610.00', 'mv-2' => '480.00']),
            Money::fromEuros('5.00')
        );

        $this->assertTrue($result->withinTolerance);
        $this->assertSame(0, $result->difference->cents);
        $this->assertFalse($result->blocksFinalization());
        $this->assertNotNull($result->allocationKey());
        $this->assertSame(61000, $result->allocationKey()?->amountFor('mv-1')->cents);
        $this->assertSame(
            CheckSeverity::PASSED,
            $result->findings[0]->severity
        );
    }

    #[Test]
    public function geringe_abweichung_innerhalb_der_toleranz_wird_nur_dokumentiert(): void
    {
        // 610,00 + 478,50 = 1.088,50 EUR gegenüber 1.090,00 EUR: Abweichung
        // 1,50 EUR bei einer Toleranz von 5,00 EUR.
        $result = $this->reconciler->reconcile(
            $this->statement('1090.00', ['mv-1' => '610.00', 'mv-2' => '478.50']),
            Money::fromEuros('5.00')
        );

        $this->assertTrue($result->withinTolerance);
        $this->assertSame(-150, $result->difference->cents);
        $this->assertFalse($result->blocksFinalization());
        $this->assertNotNull($result->allocationKey());
    }

    #[Test]
    public function abweichung_ueber_der_toleranz_blockiert_die_finalisierung(): void
    {
        // 610,00 + 400,00 = 1.010,00 EUR gegenüber 1.090,00 EUR:
        // Abweichung 80,00 EUR bei einer Toleranz von 5,00 EUR.
        $result = $this->reconciler->reconcile(
            $this->statement('1090.00', ['mv-1' => '610.00', 'mv-2' => '400.00']),
            Money::fromEuros('5.00')
        );

        $this->assertFalse($result->withinTolerance);
        $this->assertSame(-8000, $result->difference->cents);
        $this->assertTrue($result->blocksFinalization());
        $this->assertNull($result->allocationKey());
        $this->assertSame(
            CheckCode::HEATING_CHECKSUM_OUT_OF_TOLERANCE,
            $result->findings[0]->code
        );
        $this->assertStringContainsString('Toleranz von 5,00 EUR ist überschritten', $result->findings[0]->message);
    }

    #[Test]
    public function unklarer_co2_status_erzeugt_eine_pruefaufgabe(): void
    {
        $result = $this->reconciler->reconcile(
            $this->statement('1090.00', ['mv-1' => '610.00', 'mv-2' => '480.00'], Co2AllocationStatus::UNKNOWN),
            Money::fromEuros('5.00')
        );

        $co2Findings = array_values(array_filter(
            $result->findings,
            static fn ($finding): bool => $finding->code === CheckCode::HEATING_CO2_STATUS_UNKNOWN
        ));

        $this->assertCount(1, $co2Findings);
        $this->assertSame(CheckSeverity::WARNING, $co2Findings[0]->severity);
    }

    #[Test]
    public function enthaltene_co2_aufteilung_erzeugt_keine_pruefaufgabe(): void
    {
        $result = $this->reconciler->reconcile(
            $this->statement('1090.00', ['mv-1' => '610.00', 'mv-2' => '480.00'], Co2AllocationStatus::INCLUDED),
            Money::fromEuros('5.00')
        );

        $this->assertCount(1, $result->findings);
        $this->assertFalse($result->blocksFinalization());
    }

    #[Test]
    public function dezentrale_versorgung_erzeugt_keine_heizkostenzeilen(): void
    {
        $findings = $this->reconciler->decentralizedSupply();

        $this->assertCount(1, $findings);
        $this->assertSame(CheckCode::HEATING_DECENTRALIZED_NO_COSTS, $findings[0]->code);
        $this->assertFalse($findings[0]->blocksFinalization());
        $this->assertFalse(HeatingSupplyType::DECENTRALIZED->producesHeatingLines());
        $this->assertTrue(HeatingSupplyType::EXTERNAL_STATEMENT->producesHeatingLines());
    }

    /**
     * @param  array<string, string>  $amounts
     */
    private function statement(
        string $total,
        array $amounts,
        Co2AllocationStatus $co2Status = Co2AllocationStatus::INCLUDED,
    ): ExternalHeatingStatementInput {
        $money = [];

        foreach ($amounts as $participantKey => $amount) {
            $money[$participantKey] = Money::fromEuros($amount);
        }

        return new ExternalHeatingStatementInput(
            'ista',
            DatePeriodRange::calendarYear(2025),
            Money::fromEuros($total),
            $money,
            $co2Status
        );
    }
}
