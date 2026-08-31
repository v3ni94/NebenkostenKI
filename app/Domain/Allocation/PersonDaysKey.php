<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Period\DatePeriodRange;
use App\Domain\Support\GermanNumberFormatter;
use Brick\Math\BigDecimal;

/**
 * Verteilung nach Personentagen.
 *
 * Personentage = Personenanzahl mal Gültigkeitstage. Die Zeitgewichtung ist
 * damit bereits im Zähler enthalten; die Engine setzt für diesen Schlüssel
 * den zusätzlichen Zeitfaktor auf exakt 1, damit die Zeit nicht doppelt
 * berücksichtigt wird (Bezugsebene OCCUPANCY).
 *
 * Der Zähler bezieht sich auf den Nutzungszeitraum (Mietverhältnis oder
 * Leerstand), nicht auf die Einheit.
 */
final class PersonDaysKey extends NumericAllocationKey
{
    /** @var array<string, list<PersonDaysSegment>> */
    private array $segments = [];

    private ?DatePeriodRange $billingPeriod = null;

    public function type(): AllocationKeyType
    {
        return AllocationKeyType::PERSON_DAYS;
    }

    /**
     * Erzeugt den Schlüssel aus Personenabschnitten, begrenzt auf den
     * Abrechnungszeitraum.
     *
     * @param  list<PersonDaysSegment>  $segments
     */
    public static function fromSegments(array $segments, DatePeriodRange $billingPeriod): self
    {
        $values = [];
        $grouped = [];

        foreach ($segments as $segment) {
            $values[$segment->participantKey] = ($values[$segment->participantKey] ?? 0)
                + $segment->personDaysWithin($billingPeriod);
            $grouped[$segment->participantKey][] = $segment;
        }

        $key = new self($values);
        $key->segments = $grouped;
        $key->billingPeriod = $billingPeriod;

        return $key;
    }

    protected function displayScale(): int
    {
        return 0;
    }

    public function explanationFor(string $participantKey): string
    {
        $base = sprintf(
            'Personentage %s von %s',
            GermanNumberFormatter::decimal($this->numeratorFor($participantKey), 0),
            GermanNumberFormatter::decimal($this->denominator(), 0)
        );

        $detail = $this->segmentDetail($participantKey);

        return $detail === null ? $base : $base.' ('.$detail.')';
    }

    /**
     * Rechenweg der Personentage, z. B. "3 Personen × 181 Tage + 2 Personen × 184 Tage".
     */
    public function segmentDetail(string $participantKey): ?string
    {
        if (! isset($this->segments[$participantKey]) || ! $this->billingPeriod instanceof DatePeriodRange) {
            return null;
        }

        $parts = [];

        foreach ($this->segments[$participantKey] as $segment) {
            $days = $segment->daysWithin($this->billingPeriod);

            if ($days === 0) {
                continue;
            }

            $parts[] = sprintf('%d Personen × %d Tage', $segment->persons, $days);
        }

        return $parts === [] ? null : implode(' + ', $parts);
    }

    /**
     * @param  array<string, BigDecimal|string|int>  $values
     */
    public static function fromPersonDays(array $values): self
    {
        return new self($values);
    }
}
