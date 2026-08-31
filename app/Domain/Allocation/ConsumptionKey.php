<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Support\GermanNumberFormatter;
use Brick\Math\BigDecimal;

/**
 * Verteilung nach erfasstem Verbrauch (z. B. m³ Wasser, kWh, Einheiten).
 *
 * Der Zähler bezieht sich auf den Nutzungszeitraum (Bezugsebene OCCUPANCY),
 * weil ein Verbrauch bei Nutzerwechsel nur anhand einer Zwischenablesung
 * geteilt werden darf. Die Zeitgewichtung ist im Verbrauch enthalten; der
 * zusätzliche Zeitfaktor ist deshalb exakt 1.
 *
 * Wurde der Verbrauch ohne Zwischenablesung ersatzweise verteilt, ist das
 * über ConsumptionKeyBuilder ausdrücklich zu bestätigen; die betroffenen
 * Beteiligten werden gekennzeichnet, damit das PDF den Hinweis druckt.
 */
final class ConsumptionKey extends NumericAllocationKey
{
    /** @var list<string> */
    private array $substituteParticipants = [];

    private string $measurementUnit = '';

    public function type(): AllocationKeyType
    {
        return AllocationKeyType::CONSUMPTION;
    }

    /**
     * @param  array<string, BigDecimal|string|int>  $values
     * @param  list<string>  $substituteParticipants  Beteiligte mit bestätigter Ersatzverteilung
     */
    public static function create(
        array $values,
        string $measurementUnit = '',
        array $substituteParticipants = [],
        BigDecimal|string|int|null $denominator = null,
    ): self {
        $key = new self($values, $denominator);
        $key->measurementUnit = $measurementUnit;
        $key->substituteParticipants = $substituteParticipants;

        return $key;
    }

    public function measurementUnit(): string
    {
        return $this->measurementUnit;
    }

    /**
     * Wurde für diesen Beteiligten eine ausdrücklich bestätigte
     * Ersatzverteilung ohne Zwischenablesung verwendet?
     */
    public function usesSubstituteDistributionFor(string $participantKey): bool
    {
        return in_array($participantKey, $this->substituteParticipants, true);
    }

    /**
     * @return list<string>
     */
    public function substituteParticipants(): array
    {
        return $this->substituteParticipants;
    }

    protected function displayScale(): int
    {
        return 3;
    }

    public function explanationFor(string $participantKey): string
    {
        $text = sprintf(
            'Verbrauch %s von %s',
            GermanNumberFormatter::quantity($this->numeratorFor($participantKey), $this->measurementUnit, $this->displayScale()),
            GermanNumberFormatter::quantity($this->denominator(), $this->measurementUnit, $this->displayScale())
        );

        if ($this->usesSubstituteDistributionFor($participantKey)) {
            $text .= ' (bestätigte Ersatzverteilung ohne Zwischenablesung)';
        }

        return $text;
    }
}
