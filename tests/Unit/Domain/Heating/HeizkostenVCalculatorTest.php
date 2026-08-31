<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Heating;

use App\Domain\Calculation\Heating\HeatingCalculationNotReleasedException;
use App\Domain\Calculation\Heating\HeizkostenVCalculator;
use App\Domain\Calculation\Heating\HeizkostenVInput;
use App\Domain\Calculation\Heating\IncompleteHeatingDataException;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fall B: Zentralheizung ohne externe Abrechnung. Das Modul ist vorbereitet,
 * aber bewusst nicht freigeschaltet; bei unvollständigen Daten gibt es keine
 * scheinbar korrekte Automatik (Pflichtenheft Abschnitt 12.3).
 */
final class HeizkostenVCalculatorTest extends TestCase
{
    private HeizkostenVCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new HeizkostenVCalculator;
    }

    #[Test]
    public function unvollstaendige_daten_loesen_die_incomplete_heating_data_exception_aus(): void
    {
        $this->expectException(IncompleteHeatingDataException::class);
        $this->expectExceptionMessage('keine Schätzung');

        $this->calculator->calculate(new HeizkostenVInput(DatePeriodRange::calendarYear(2025)));
    }

    #[Test]
    public function fehlende_angaben_werden_konkret_benannt(): void
    {
        $missing = $this->calculator->missingFields(new HeizkostenVInput(
            DatePeriodRange::calendarYear(2025),
            Money::fromEuros('8400.00'),
            Money::fromEuros('320.00')
        ));

        $this->assertContains('Grundkostenanteil in Prozent', $missing);
        $this->assertContains('beheizte Wohnflächen je Einheit', $missing);
        $this->assertContains('erfasste Heizverbrauchswerte je Einheit', $missing);
        $this->assertContains('erfasste Warmwasserverbrauchswerte je Einheit', $missing);
        $this->assertContains('Brennstoffbestand zu Beginn und zum Ende des Zeitraums', $missing);
        $this->assertContains('Verfahren zur Ermittlung des Warmwasseranteils', $missing);
        $this->assertContains('CO2-Kosten', $missing);
        $this->assertContains('Stufe des CO2-Stufenmodells', $missing);
        $this->assertNotContains('Brennstoffkosten', $missing);
        $this->assertNotContains('Betriebsstrom', $missing);
        $this->assertFalse($this->calculator->hasCompleteData(new HeizkostenVInput(DatePeriodRange::calendarYear(2025))));
    }

    #[Test]
    public function vollstaendige_daten_liefern_kein_ergebnis_solange_das_modul_nicht_freigeschaltet_ist(): void
    {
        $input = $this->completeInput();

        $this->assertTrue($this->calculator->hasCompleteData($input));
        $this->assertFalse($this->calculator->isReleased());

        $this->expectException(HeatingCalculationNotReleasedException::class);
        $this->expectExceptionMessage('noch nicht freigeschaltet');

        $this->calculator->calculate($input);
    }

    #[Test]
    public function der_zulaessige_grundkostenanteil_ist_dokumentiert(): void
    {
        $this->assertSame([30, 50], $this->calculator->allowedBasicCostRange());
    }

    #[Test]
    public function die_liste_der_fehlenden_felder_bleibt_in_der_exception_verfuegbar(): void
    {
        try {
            $this->calculator->calculate(new HeizkostenVInput(DatePeriodRange::calendarYear(2025)));
            $this->fail('Es wurde keine IncompleteHeatingDataException geworfen.');
        } catch (IncompleteHeatingDataException $exception) {
            $this->assertContains('Brennstoffkosten', $exception->missingFields);
            $this->assertCount(10, $exception->missingFields);
        }
    }

    private function completeInput(): HeizkostenVInput
    {
        return new HeizkostenVInput(
            DatePeriodRange::calendarYear(2025),
            Money::fromEuros('8400.00'),
            Money::fromEuros('320.00'),
            Money::fromEuros('240.00'),
            Money::fromEuros('180.00'),
            Money::fromEuros('420.00'),
            30,
            ['W-1' => '68.00', 'W-2' => '92.00'],
            ['W-1' => '4200.000', 'W-2' => '5800.000'],
            ['W-1' => '18.000', 'W-2' => '24.000'],
            '1200.000',
            '900.000',
            'Wärmemengenzähler',
            2,
            '38.500'
        );
    }
}
