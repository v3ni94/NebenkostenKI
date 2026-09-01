<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Heating;

use App\Application\Heating\EuroAmountInput;
use App\Application\Heating\InvalidAmountException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Umrechnung der Betragseingabe auf Cent, ausschliesslich ueber BigDecimal
 * und ohne float-Zwischenschritt (Grundsatz 8).
 */
final class EuroAmountInputTest extends TestCase
{
    #[Test]
    public function ein_betrag_von_1234_56_euro_wird_exakt_umgerechnet(): void
    {
        self::assertSame(123456, EuroAmountInput::parseOrZero('1.234,56')->cents);
        self::assertSame('1.234,56 EUR', EuroAmountInput::parseOrZero('1.234,56')->format());
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    public static function betraege(): array
    {
        return [
            ['1.234,56', 123456],
            ['1234,56', 123456],
            ['1234.56', 123456],
            ['1.234', 123400],
            ['0,01', 1],
            ['-12,50', -1250],
            ['12', 1200],
            [' 1.234,56 EUR ', 123456],
            ['1.234.567,89', 123456789],
            ['0,1', 10],
        ];
    }

    #[Test]
    #[DataProvider('betraege')]
    public function zulaessige_schreibweisen_werden_exakt_umgerechnet(string $eingabe, int $cent): void
    {
        self::assertSame($cent, EuroAmountInput::parseOrZero($eingabe)->cents);
    }

    #[Test]
    public function ein_leerer_wert_ergibt_null_und_kein_geschaetzter_betrag(): void
    {
        self::assertNull(EuroAmountInput::parse(null));
        self::assertNull(EuroAmountInput::parse(''));
        self::assertNull(EuroAmountInput::parse('   '));
        self::assertSame(0, EuroAmountInput::parseOrZero(null)->cents);
    }

    #[Test]
    public function mehr_als_zwei_nachkommastellen_werden_abgelehnt(): void
    {
        $this->expectException(InvalidAmountException::class);
        $this->expectExceptionMessage('1.234,567');

        EuroAmountInput::parse('1.234,567');
    }

    #[Test]
    public function ein_text_wird_abgelehnt(): void
    {
        $this->expectException(InvalidAmountException::class);

        EuroAmountInput::parse('etwa zwölf Euro');
    }

    #[Test]
    public function die_pruefung_meldet_zulaessige_und_unzulaessige_werte(): void
    {
        self::assertTrue(EuroAmountInput::isValid('1.234,56'));
        self::assertTrue(EuroAmountInput::isValid(null));
        self::assertFalse(EuroAmountInput::isValid('1,234,56'));
        self::assertFalse(EuroAmountInput::isValid('12,345'));
    }

    #[Test]
    public function die_umrechnung_verwendet_keinen_float_zwischenschritt(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 4).'/app/Application/Heating/EuroAmountInput.php');

        self::assertIsString($quelle);
        self::assertStringNotContainsString('(float)', $quelle);
        self::assertStringNotContainsString('floatval', $quelle);
        self::assertStringNotContainsString('number_format', $quelle);
        self::assertStringNotContainsString('round(', $quelle);
    }
}
