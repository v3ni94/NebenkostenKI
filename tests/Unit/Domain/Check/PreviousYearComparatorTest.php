<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Check;

use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Calculation\Check\PreviousYearCategoryAmount;
use App\Domain\Calculation\Check\PreviousYearComparator;
use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Plausibilitätsvergleich gegen das Vorjahr, Standardwarnung ab 30 Prozent
 * (Pflichtenheft Abschnitt 12.5).
 */
final class PreviousYearComparatorTest extends TestCase
{
    private PreviousYearComparator $comparator;

    protected function setUp(): void
    {
        $this->comparator = new PreviousYearComparator;
    }

    #[Test]
    public function abweichung_ab_dreissig_prozent_wird_gewarnt(): void
    {
        // 1.019,00 EUR gegenüber 760,00 EUR: 259,00 / 760,00 = 34,1 Prozent.
        $findings = $this->comparator->compare(
            [$this->cost('GARTENPFLEGE', 'Gartenpflege', '1019.00')],
            [new PreviousYearCategoryAmount('GARTENPFLEGE', 'Gartenpflege', Money::fromEuros('760.00'))]
        );

        $this->assertCount(1, $findings);
        $this->assertSame(CheckCode::PREVIOUS_YEAR_DEVIATION, $findings[0]->code);
        $this->assertStringContainsString('34,1 Prozent', $findings[0]->message);
        $this->assertStringContainsString('259,00 EUR', $findings[0]->message);
    }

    #[Test]
    public function abweichung_unter_der_schwelle_wird_nicht_gemeldet(): void
    {
        // 900,00 gegenüber 880,00 EUR: 2,3 Prozent.
        $findings = $this->comparator->compare(
            [$this->cost('MUELL', 'Müllbeseitigung', '900.00')],
            [new PreviousYearCategoryAmount('MUELL', 'Müllbeseitigung', Money::fromEuros('880.00'))]
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function rueckgang_um_mehr_als_dreissig_prozent_wird_ebenfalls_gewarnt(): void
    {
        // 600,00 gegenüber 1.000,00 EUR: -40,0 Prozent.
        $findings = $this->comparator->compare(
            [$this->cost('WASSER', 'Wasserversorgung', '600.00')],
            [new PreviousYearCategoryAmount('WASSER', 'Wasserversorgung', Money::fromEuros('1000.00'))]
        );

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('-40,0 Prozent', $findings[0]->message);
    }

    #[Test]
    public function abweichende_schwelle_kann_uebergeben_werden(): void
    {
        $findings = $this->comparator->compare(
            [$this->cost('MUELL', 'Müllbeseitigung', '900.00')],
            [new PreviousYearCategoryAmount('MUELL', 'Müllbeseitigung', Money::fromEuros('880.00'))],
            2
        );

        $this->assertCount(1, $findings);
        $this->assertSame(2, $findings[0]->context['thresholdPercent']);
    }

    #[Test]
    public function im_vorjahr_vorhandene_und_jetzt_fehlende_kategorie_wird_gemeldet(): void
    {
        $findings = $this->comparator->compare(
            [$this->cost('MUELL', 'Müllbeseitigung', '900.00')],
            [
                new PreviousYearCategoryAmount('MUELL', 'Müllbeseitigung', Money::fromEuros('880.00')),
                new PreviousYearCategoryAmount('ALLGEMEINSTROM', 'Allgemeinstrom', Money::fromEuros('300.00')),
            ]
        );

        $this->assertCount(1, $findings);
        $this->assertSame(CheckCode::PREVIOUS_YEAR_CATEGORY_MISSING, $findings[0]->code);
        $this->assertStringContainsString('Allgemeinstrom', $findings[0]->message);
    }

    #[Test]
    public function neue_kategorie_ohne_vorjahresvergleich_wird_als_hinweis_gemeldet(): void
    {
        $findings = $this->comparator->compare(
            [
                $this->cost('MUELL', 'Müllbeseitigung', '900.00'),
                $this->cost('SCHORNSTEINFEGER', 'Schornsteinreinigung', '120.00'),
            ],
            [new PreviousYearCategoryAmount('MUELL', 'Müllbeseitigung', Money::fromEuros('880.00'))]
        );

        $this->assertCount(1, $findings);
        $this->assertSame(CheckCode::PREVIOUS_YEAR_CATEGORY_NEW, $findings[0]->code);
        $this->assertStringContainsString('Schornsteinreinigung', $findings[0]->message);
    }

    #[Test]
    public function positionen_derselben_kategorie_werden_vor_dem_vergleich_summiert(): void
    {
        // Rechnung 1.200,00 EUR und Gutschrift -181,00 EUR ergeben 1.019,00 EUR.
        $findings = $this->comparator->compare(
            [
                $this->cost('GARTENPFLEGE', 'Gartenpflege', '1200.00'),
                $this->cost('GARTENPFLEGE', 'Gutschrift Gartenpflege', '-181.00'),
            ],
            [new PreviousYearCategoryAmount('GARTENPFLEGE', 'Gartenpflege', Money::fromEuros('760.00'))]
        );

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('1.019,00 EUR', $findings[0]->message);
    }

    private function cost(string $categoryKey, string $label, string $amount): CostItemInput
    {
        return new CostItemInput(
            'k-'.$categoryKey.'-'.$amount,
            $categoryKey,
            $label,
            Money::fromEuros($amount),
            'flaeche',
            AllocabilityStatus::ALLOCABLE
        );
    }
}
