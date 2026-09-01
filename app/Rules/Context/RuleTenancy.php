<?php

declare(strict_types=1);

namespace App\Rules\Context;

use App\Domain\Period\DatePeriodRange;

/**
 * Ein Mietverhaeltnis mit Nutzungszeitraum und Zustellangaben.
 *
 * "hasMovedOut" bedeutet, dass das Mietverhaeltnis vor dem Ende des
 * Abrechnungszeitraums geendet hat. In diesem Fall ist eine Zustellanschrift
 * erforderlich, damit die Abrechnung den Mieter erreicht.
 */
final readonly class RuleTenancy
{
    /**
     * @param  list<DatePeriodRange>  $occupancyPeriods
     */
    public function __construct(
        public string $key,
        public string $unitKey,
        public string $displayName,
        public DatePeriodRange $period,
        public bool $hasMovedOut = false,
        public bool $hasDeliveryAddress = true,
        public ?bool $otherOperatingCostsAgreed = null,
        public array $occupancyPeriods = [],
    ) {}
}
