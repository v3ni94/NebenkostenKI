<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Brick\Math\BigDecimal;

/**
 * Direktzuordnung: je Beteiligtem ist ein fester Betrag zugeordnet.
 *
 * Wird für externe Heizkostenabrechnungen (Fall A), direkt zugeordnete
 * Grundsteuer und Einzelbelege einer bestimmten Einheit verwendet. Die
 * Zähler sind Beträge in Cent, der Nenner ist deren Summe. Damit ergibt die
 * Verteilung des Gesamtbetrags exakt die zugeordneten Einzelbeträge.
 *
 * Bezugsebene OCCUPANCY: bei Mieterwechsel wird der Betrag ausdrücklich dem
 * jeweiligen Nutzungszeitraum zugeordnet, nicht der Einheit; der zusätzliche
 * Zeitfaktor ist deshalb exakt 1.
 *
 * Die Zähler sind immer positive Gewichte. Eine Gutschrift wird über einen
 * negativen Betrag der Kostenzeile abgebildet; die Verteilung liefert dann
 * negative Anteile in derselben Gewichtung.
 */
final class DirectAssignmentKey extends NumericAllocationKey
{
    public function type(): AllocationKeyType
    {
        return AllocationKeyType::DIRECT_ASSIGNMENT;
    }

    /**
     * @param  array<string, Money>  $amounts
     */
    public static function fromAmounts(array $amounts): self
    {
        $values = [];

        foreach ($amounts as $participantKey => $amount) {
            $values[$participantKey] = $amount->cents;
        }

        return new self($values);
    }

    /**
     * Zugeordneter Betrag des Beteiligten.
     */
    public function amountFor(string $participantKey): Money
    {
        return Money::fromCents($this->numeratorFor($participantKey)->toInt());
    }

    /**
     * Summe aller zugeordneten Beträge.
     */
    public function totalAmount(): Money
    {
        return Money::fromCents($this->denominator()->toInt(), Currency::EUR);
    }

    protected function displayScale(): int
    {
        return 2;
    }

    public function explanationFor(string $participantKey): string
    {
        return sprintf(
            'Direktzuordnung %s von %s',
            $this->amountFor($participantKey)->format(),
            $this->totalAmount()->format()
        );
    }

    public function formattedNumeratorFor(string $participantKey): string
    {
        return $this->amountFor($participantKey)->formatAmount();
    }

    public function formattedDenominator(): string
    {
        return $this->totalAmount()->formatAmount();
    }

    /**
     * @param  array<string, BigDecimal|string|int>  $values
     */
    public static function fromCentValues(array $values): self
    {
        return new self($values);
    }
}
