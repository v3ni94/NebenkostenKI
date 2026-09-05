<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Money;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Brick\Math\Exception\RoundingNecessaryException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Geldbeträge werden ausschließlich als Integer in Cent geführt
 * (Grundsatz 8 des Pflichtenhefts).
 */
final class MoneyTest extends TestCase
{
    #[Test]
    public function betrag_wird_als_integer_cent_gefuehrt(): void
    {
        $money = Money::fromCents(123456);

        $this->assertSame(123456, $money->cents);
        $this->assertSame(Currency::EUR, $money->currency);
    }

    #[Test]
    public function euro_eingabe_wird_exakt_in_cent_umgerechnet(): void
    {
        $this->assertSame(123456, Money::fromEuros('1234.56')->cents);
        $this->assertSame(123456, Money::fromEuros('1234,56')->cents);
        $this->assertSame(-4500, Money::fromEuros('-45.00')->cents);
        $this->assertSame(700, Money::fromEuros(7)->cents);
    }

    #[Test]
    public function eine_dritte_dezimalstelle_wird_nicht_still_gerundet(): void
    {
        $this->expectException(RoundingNecessaryException::class);

        Money::fromEuros('12.345');
    }

    #[Test]
    public function addition_subtraktion_und_negation_rechnen_in_cent(): void
    {
        $a = Money::fromEuros('1234.56');
        $b = Money::fromEuros('265.44');

        $this->assertSame(150000, $a->plus($b)->cents);
        $this->assertSame(96912, $a->minus($b)->cents);
        $this->assertSame(-123456, $a->negated()->cents);
        $this->assertSame(123456, $a->negated()->absolute()->cents);
    }

    #[Test]
    public function vergleich_und_vorzeichen_werden_korrekt_bewertet(): void
    {
        $guthaben = Money::fromEuros('-45.38');
        $nachzahlung = Money::fromEuros('184.38');

        $this->assertTrue($guthaben->isNegative());
        $this->assertTrue($nachzahlung->isPositive());
        $this->assertTrue(Money::zero()->isZero());
        $this->assertSame(-1, $guthaben->compareTo($nachzahlung));
        $this->assertSame(1, $nachzahlung->compareTo($guthaben));
        $this->assertSame(0, $nachzahlung->compareTo(Money::fromCents(18438)));
        $this->assertTrue($nachzahlung->isGreaterThan($guthaben));
        $this->assertTrue($guthaben->isLessThan($nachzahlung));
        $this->assertTrue($nachzahlung->equals(Money::fromCents(18438)));
    }

    #[Test]
    public function summierung_liefert_bei_leerer_liste_null_euro(): void
    {
        $this->assertSame(0, Money::sum()->cents);
        $this->assertSame(0, Money::sumOf([])->cents);
        $this->assertSame(
            250042,
            Money::sum(Money::fromCents(200000), Money::fromCents(50042))->cents
        );
        $this->assertSame(
            2504220,
            Money::sumOf([Money::fromCents(2468940), Money::fromCents(35280)])->cents
        );
    }

    #[Test]
    public function deutsche_formatierung_nutzt_punkt_und_komma(): void
    {
        $this->assertSame('1.234,56 EUR', Money::fromCents(123456)->format());
        $this->assertSame('0,00 EUR', Money::zero()->format());
        $this->assertSame('-45,38 EUR', Money::fromCents(-4538)->format());
        $this->assertSame('25.042,20 EUR', Money::fromCents(2504220)->format());
        $this->assertSame('1.234.567,89 EUR', Money::fromCents(123456789)->format());
        $this->assertSame('1.234,56', Money::fromCents(123456)->formatAmount());
        $this->assertSame('9,05 EUR', (string) Money::fromCents(905));
    }

    #[Test]
    public function exakte_dezimal_und_bruchdarstellung_stehen_zur_verfuegung(): void
    {
        $money = Money::fromCents(346635);

        $this->assertSame('3466.35', (string) $money->toDecimalEuros());
        $this->assertSame('346635', (string) $money->toRationalCents()->getNumerator());
    }
}
