<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Period\DatePeriodRange;
use InvalidArgumentException;

/**
 * Ein Zeitabschnitt mit konstanter Personenanzahl.
 *
 * Personentage = Personenanzahl mal Gültigkeitstage
 * (Pflichtenheft Abschnitt 11.2). Start- und Endtag zählen mit.
 */
final readonly class PersonDaysSegment
{
    public function __construct(
        public string $participantKey,
        public int $persons,
        public DatePeriodRange $period,
    ) {
        if ($persons < 0) {
            throw new InvalidArgumentException('Die Personenanzahl darf nicht negativ sein.');
        }
    }

    /**
     * Personentage innerhalb des Abrechnungszeitraums.
     */
    public function personDaysWithin(DatePeriodRange $billingPeriod): int
    {
        return $this->persons * $billingPeriod->overlappingDays($this->period);
    }

    public function daysWithin(DatePeriodRange $billingPeriod): int
    {
        return $billingPeriod->overlappingDays($this->period);
    }
}
