<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Support\GermanNumberFormatter;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * Basisimplementierung für alle wertbasierten Verteilerschlüssel.
 *
 * Der Nenner ist standardmäßig die Summe der Zähler; abweichende Nenner sind
 * ausdrücklich zulässig (z. B. MEA-Gesamtnenner 1.000/1.000, wenn das Objekt
 * nur einen Teil der WEG umfasst). Übersteigt die Summe der Zähler den
 * Nenner, liegt ein Datenfehler vor und es wird eine Domain-Exception
 * geworfen.
 *
 * Validierung bei Konstruktion, damit ein unbrauchbarer Schlüssel niemals in
 * die Berechnung gelangt:
 * - leere Wertelisten,
 * - negative Zähler,
 * - Nenner null oder negativ.
 */
abstract class NumericAllocationKey implements AllocationKey
{
    /** @var array<string, BigDecimal> */
    protected array $numerators = [];

    protected BigDecimal $denominator;

    /**
     * @param  array<string, BigDecimal|string|int>  $values  Zähler je Beteiligtem
     * @param  BigDecimal|string|int|null  $denominator  abweichender Gesamtnenner
     */
    public function __construct(array $values, BigDecimal|string|int|null $denominator = null)
    {
        if ($values === []) {
            throw InvalidAllocationKeyException::emptyKey($this->type());
        }

        $sum = BigDecimal::zero();

        foreach ($values as $participantKey => $value) {
            $decimal = BigDecimal::of(is_string($value) ? str_replace(',', '.', $value) : $value);

            if ($decimal->isNegative()) {
                throw InvalidAllocationKeyException::negativeNumerator(
                    $this->type(),
                    (string) $participantKey,
                    (string) $decimal
                );
            }

            $this->numerators[(string) $participantKey] = $decimal;
            $sum = $sum->plus($decimal);
        }

        ksort($this->numerators);

        $effectiveDenominator = $denominator === null
            ? $sum
            : BigDecimal::of(is_string($denominator) ? str_replace(',', '.', $denominator) : $denominator);

        if ($effectiveDenominator->isNegative()) {
            throw InvalidAllocationKeyException::negativeDenominator($this->type(), (string) $effectiveDenominator);
        }

        if ($effectiveDenominator->isZero()) {
            throw InvalidAllocationKeyException::zeroDenominator($this->type());
        }

        if ($sum->isGreaterThan($effectiveDenominator)) {
            throw InvalidAllocationKeyException::numeratorsExceedDenominator(
                $this->type(),
                (string) $sum,
                (string) $effectiveDenominator
            );
        }

        $this->denominator = $effectiveDenominator;
    }

    public function scope(): AllocationKeyScope
    {
        return $this->type()->scope();
    }

    public function label(): string
    {
        return $this->type()->label();
    }

    public function participantKeys(): array
    {
        // PHP normalisiert rein numerische Array-Schlüssel zu Integern;
        // nach außen bleiben Beteiligtenschlüssel immer Strings.
        return array_map(static fn (int|string $key): string => (string) $key, array_keys($this->numerators));
    }

    public function hasParticipant(string $participantKey): bool
    {
        return array_key_exists($participantKey, $this->numerators);
    }

    public function numeratorFor(string $participantKey): BigDecimal
    {
        return $this->numerators[$participantKey] ?? BigDecimal::zero();
    }

    public function denominator(): BigDecimal
    {
        return $this->denominator;
    }

    public function shareFor(string $participantKey): BigRational
    {
        return $this->numeratorFor($participantKey)
            ->toBigRational()
            ->dividedBy($this->denominator->toBigRational());
    }

    /**
     * Summe aller Zähler; kleiner als der Nenner bedeutet, dass ein Restanteil
     * außerhalb der erfassten Beteiligten liegt.
     */
    public function numeratorSum(): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ($this->numerators as $numerator) {
            $sum = $sum->plus($numerator);
        }

        return $sum;
    }

    public function explanationFor(string $participantKey): string
    {
        $unit = $this->type()->unitOfMeasure();

        return sprintf(
            '%s %s von %s',
            $this->label(),
            GermanNumberFormatter::quantity($this->numeratorFor($participantKey), $unit, $this->displayScale()),
            GermanNumberFormatter::quantity($this->denominator, $unit, $this->displayScale())
        );
    }

    public function formattedNumeratorFor(string $participantKey): string
    {
        return GermanNumberFormatter::decimal($this->numeratorFor($participantKey), $this->displayScale());
    }

    public function formattedDenominator(): string
    {
        return GermanNumberFormatter::decimal($this->denominator, $this->displayScale());
    }

    /**
     * Anzahl der Dezimalstellen in Erklärungstexten.
     */
    protected function displayScale(): int
    {
        return 2;
    }
}
