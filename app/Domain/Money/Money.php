<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Domain\Support\GermanNumberFormatter;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * Geldbetrag als Integer in Cent.
 *
 * Grundsatz 8 des Pflichtenhefts: Geldbeträge werden ausschließlich als
 * Integer in Cent gespeichert und berechnet, niemals als binäre Floats.
 * Die Klasse ist unveränderlich; jede Rechenoperation liefert eine neue
 * Instanz. Ein negativer Betrag ist fachlich zulässig (Gutschrift, Guthaben).
 *
 * Die Persistenzschicht bildet ihre eigenen Enums/Spalten auf
 * App\Domain\Money\Currency ab; die Domain kennt keine Eloquent-Casts.
 */
final readonly class Money
{
    private function __construct(
        public int $cents,
        public Currency $currency,
    ) {}

    public static function fromCents(int $cents, Currency $currency = Currency::EUR): self
    {
        return new self($cents, $currency);
    }

    public static function zero(Currency $currency = Currency::EUR): self
    {
        return new self(0, $currency);
    }

    /**
     * Erzeugt einen Betrag aus einem exakten Dezimalwert in Euro.
     *
     * Nur exakte Werte mit maximal zwei Dezimalstellen sind zulässig; ein
     * Wert wie '12,345' würde eine stille Rundung bedeuten und wird daher
     * von brick/math abgelehnt.
     */
    public static function fromEuros(BigDecimal|string|int $euros, Currency $currency = Currency::EUR): self
    {
        $decimal = BigDecimal::of(is_string($euros) ? str_replace(',', '.', $euros) : $euros);

        return new self($decimal->withPointMovedRight(2)->toInt(), $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->cents, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->cents), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->cents <=> $other->cents;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * Summiert beliebig viele Beträge. Ohne Argument ergibt sich 0,00 EUR.
     */
    public static function sum(self ...$amounts): self
    {
        if ($amounts === []) {
            return self::zero();
        }

        $total = $amounts[0];

        foreach (array_slice($amounts, 1) as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    /**
     * Summiert eine Liste von Beträgen; die Währung wird bei leerer Liste
     * aus dem Parameter übernommen.
     *
     * @param  iterable<self>  $amounts
     */
    public static function sumOf(iterable $amounts, Currency $currency = Currency::EUR): self
    {
        $total = self::zero($currency);

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    /**
     * Exakter Betrag in Cent als Bruch, für die interne Weiterverarbeitung
     * mit hoher Dezimalpräzision.
     */
    public function toRationalCents(): BigRational
    {
        return BigRational::nd($this->cents, 1);
    }

    /**
     * Exakter Betrag in Euro mit zwei Dezimalstellen.
     */
    public function toDecimalEuros(): BigDecimal
    {
        return BigDecimal::ofUnscaledValue($this->cents, 2);
    }

    /**
     * Deutsche Darstellung mit Währung, z. B. "1.234,56 EUR".
     */
    public function format(): string
    {
        return $this->formatAmount().' '.$this->currency->symbol();
    }

    /**
     * Deutsche Darstellung ohne Währung, z. B. "1.234,56".
     */
    public function formatAmount(): string
    {
        return GermanNumberFormatter::decimal($this->toDecimalEuros(), 2);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatchException::between($this->currency, $other->currency);
        }
    }
}
