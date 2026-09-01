<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Heating;

use App\Domain\Calculation\Heating\HeizkostenVCalculator;
use App\Domain\Calculation\Heating\HeizkostenVInput;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Fall B: Eine Eigenberechnung nach Heizkostenverordnung ist bewusst nicht
 * Teil des Leistungsumfangs. Die Klasse prueft nur noch die Vollstaendigkeit
 * der Angaben und wirft keine Ausnahme mehr, die eine Freischaltung
 * suggeriert.
 */
final class HeizkostenVCalculatorTest extends TestCase
{
    private HeizkostenVCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new HeizkostenVCalculator;
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
    public function ohne_angaben_fehlen_alle_zehn_felder(): void
    {
        $this->assertCount(10, $this->calculator->missingFields(new HeizkostenVInput(DatePeriodRange::calendarYear(2025))));
    }

    #[Test]
    public function vollstaendige_daten_werden_erkannt_ohne_eigene_berechnung(): void
    {
        $this->assertTrue($this->calculator->hasCompleteData($this->completeInput()));
    }

    #[Test]
    public function die_klasse_bietet_keine_eigenberechnung_an(): void
    {
        $methoden = array_map(
            static fn (ReflectionMethod $methode): string => $methode->getName(),
            (new ReflectionClass(HeizkostenVCalculator::class))->getMethods()
        );

        $this->assertNotContains('calculate', $methoden, 'Eine Eigenberechnung ist bewusst nicht vorgesehen.');
        $this->assertNotContains('isReleased', $methoden, 'Es darf keine Freischaltung suggeriert werden.');
        $this->assertSame(['missingFields', 'hasCompleteData', 'allowedBasicCostRange'], $methoden);
    }

    #[Test]
    public function der_klassenkommentar_kennzeichnet_die_eigenberechnung_als_nicht_vorgesehen(): void
    {
        $kommentar = (new ReflectionClass(HeizkostenVCalculator::class))->getDocComment();

        $this->assertIsString($kommentar);
        $this->assertStringContainsString('BEWUSST NICHT TEIL DES', $kommentar);
        $this->assertStringNotContainsString('noch nicht freigeschaltet', $kommentar);
        $this->assertStringContainsString('manuelle Erfassung', $kommentar);
    }

    #[Test]
    public function der_zulaessige_grundkostenanteil_ist_dokumentiert(): void
    {
        $this->assertSame([30, 50], $this->calculator->allowedBasicCostRange());
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
