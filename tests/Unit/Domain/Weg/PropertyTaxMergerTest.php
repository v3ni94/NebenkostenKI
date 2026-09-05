<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Weg;

use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Weg\HausgeldPositionInput;
use App\Domain\Calculation\Weg\HausgeldPositionKind;
use App\Domain\Calculation\Weg\HausgeldStatementInput;
use App\Domain\Calculation\Weg\PropertyTaxInput;
use App\Domain\Calculation\Weg\PropertyTaxMerger;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Grundsteuer wird nur addiert, wenn sie nicht bereits enthalten ist. Bei
 * möglicher Dublette erfolgt KEINE Addition, sondern ein Prüfergebnis
 * (Pflichtenheft Abschnitt 7.3).
 */
final class PropertyTaxMergerTest extends TestCase
{
    private PropertyTaxMerger $merger;

    protected function setUp(): void
    {
        $this->merger = new PropertyTaxMerger;
    }

    #[Test]
    public function separate_grundsteuer_wird_als_eigene_position_uebernommen(): void
    {
        $result = $this->merger->merge(
            new PropertyTaxInput(
                'W-12',
                Money::fromEuros('385.20'),
                DatePeriodRange::calendarYear(2025),
                true,
                false,
                'GST-2025-4711'
            ),
            $this->statementWithoutPropertyTax(),
            DatePeriodRange::calendarYear(2025),
            'einheit'
        );

        $this->assertTrue($result->added);
        $this->assertFalse($result->possibleDuplicate);
        $this->assertNotNull($result->costItem);
        $this->assertSame(38520, $result->costItem->totalAmount->cents);
        $this->assertSame('GRUNDSTEUER', $result->costItem->categoryKey);
        $this->assertSame('Grundsteuer', $result->costItem->categoryLabel);
        $this->assertStringContainsString('GST-2025-4711', (string) $result->costItem->documentReference);
        $this->assertSame(CheckCode::PROPERTY_TAX_ADDED, $result->findings[0]->code);
        $this->assertFalse($result->blocksFinalization());
        $this->assertCount(1, $result->costItems());
    }

    #[Test]
    public function in_der_hausgeldabrechnung_enthaltene_grundsteuer_wird_nicht_addiert(): void
    {
        $result = $this->merger->merge(
            new PropertyTaxInput('W-12', Money::fromEuros('385.20'), DatePeriodRange::calendarYear(2025)),
            $this->statementWithPropertyTax(),
            DatePeriodRange::calendarYear(2025),
            'einheit'
        );

        $this->assertFalse($result->added);
        $this->assertTrue($result->possibleDuplicate);
        $this->assertNull($result->costItem);
        $this->assertSame([], $result->costItems());
        $this->assertSame(CheckCode::PROPERTY_TAX_POSSIBLE_DUPLICATE, $result->findings[0]->code);
        $this->assertStringContainsString('NICHT zusätzlich angesetzt', $result->findings[0]->message);
        $this->assertStringContainsString('in der Hausgeldabrechnung', $result->findings[0]->message);
    }

    #[Test]
    public function in_einer_anderen_kostenliste_enthaltene_grundsteuer_wird_nicht_addiert(): void
    {
        $result = $this->merger->merge(
            new PropertyTaxInput('W-12', Money::fromEuros('385.20'), DatePeriodRange::calendarYear(2025)),
            $this->statementWithoutPropertyTax(),
            DatePeriodRange::calendarYear(2025),
            'einheit',
            ['WASSER', 'GRUNDSTEUER']
        );

        $this->assertFalse($result->added);
        $this->assertTrue($result->possibleDuplicate);
        $this->assertStringContainsString('in einer anderen Kostenliste', $result->findings[0]->message);
    }

    #[Test]
    public function nicht_eindeutig_zugeordnete_grundsteuer_wird_nicht_uebernommen(): void
    {
        $result = $this->merger->merge(
            new PropertyTaxInput(
                'W-12',
                Money::fromEuros('385.20'),
                DatePeriodRange::calendarYear(2025),
                false
            ),
            $this->statementWithoutPropertyTax(),
            DatePeriodRange::calendarYear(2025),
            'einheit'
        );

        $this->assertFalse($result->added);
        $this->assertFalse($result->possibleDuplicate);
        $this->assertStringContainsString('nicht eindeutig der Einheit zugeordnet', $result->findings[0]->message);
    }

    #[Test]
    public function abweichender_zeitraum_wird_nicht_geraten(): void
    {
        $result = $this->merger->merge(
            new PropertyTaxInput(
                'W-12',
                Money::fromEuros('385.20'),
                DatePeriodRange::fromIso('2025-04-01', '2025-12-31')
            ),
            $this->statementWithoutPropertyTax(),
            DatePeriodRange::calendarYear(2025),
            'einheit'
        );

        $this->assertFalse($result->added);
        $this->assertSame(CheckCode::COST_OUTSIDE_BILLING_PERIOD, $result->findings[0]->code);
        $this->assertStringContainsString('nicht geschätzt', $result->findings[0]->message);
    }

    #[Test]
    public function bestaetigter_teilzeitraum_wird_uebernommen(): void
    {
        $result = $this->merger->merge(
            new PropertyTaxInput(
                'W-12',
                Money::fromEuros('288.90'),
                DatePeriodRange::fromIso('2025-04-01', '2025-12-31'),
                true,
                true
            ),
            $this->statementWithoutPropertyTax(),
            DatePeriodRange::calendarYear(2025),
            'einheit'
        );

        $this->assertTrue($result->added);
        $this->assertSame(28890, $result->costItem?->totalAmount->cents);
    }

    private function statementWithoutPropertyTax(): HausgeldStatementInput
    {
        return new HausgeldStatementInput('W-12', DatePeriodRange::calendarYear(2025), [
            new HausgeldPositionInput(
                'p-1',
                'Wasser und Abwasser',
                'WASSER',
                Money::fromEuros('412.80')
            ),
        ]);
    }

    private function statementWithPropertyTax(): HausgeldStatementInput
    {
        return new HausgeldStatementInput('W-12', DatePeriodRange::calendarYear(2025), [
            new HausgeldPositionInput(
                'p-1',
                'Wasser und Abwasser',
                'WASSER',
                Money::fromEuros('412.80')
            ),
            new HausgeldPositionInput(
                'p-2',
                'Grundsteuer',
                'GRUNDSTEUER',
                Money::fromEuros('385.20'),
                HausgeldPositionKind::PROPERTY_TAX
            ),
        ]);
    }
}
