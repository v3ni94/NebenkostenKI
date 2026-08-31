<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Dto;

use App\Domain\Allocation\AllocationKey;
use App\Domain\Calculation\OccupancyKind;
use App\Domain\Period\DatePeriodRange;

/**
 * Vollständige, bereits validierte Eingabe eines Abrechnungslaufs.
 *
 * Die Domain-Schicht erhält ausschließlich geprüfte Daten und liefert
 * reproduzierbare Ergebnisse. Es besteht keine Abhängigkeit zu HTTP,
 * Eloquent, Laravel-Facades, Stripe oder einem KI-Provider.
 */
final readonly class StatementCalculationInput
{
    /**
     * @param  list<UnitInput>  $units
     * @param  list<OccupancyInput>  $occupancies  Mietverhältnisse und erfasste Leerstände
     * @param  list<CostItemInput>  $costItems
     * @param  array<string, AllocationKey>  $allocationKeys  Schlüsselreferenz => Verteilerschlüssel
     * @param  list<PrepaymentInput>  $prepayments
     */
    public function __construct(
        public DatePeriodRange $billingPeriod,
        public array $units,
        public array $occupancies,
        public array $costItems,
        public array $allocationKeys,
        public array $prepayments = [],
        public string $propertyLabel = '',
    ) {}

    /**
     * @return list<OccupancyInput>
     */
    public function occupanciesForUnit(string $unitKey): array
    {
        return array_values(array_filter(
            $this->occupancies,
            static fn (OccupancyInput $occupancy): bool => $occupancy->unitKey === $unitKey
        ));
    }

    /**
     * @return list<OccupancyInput>
     */
    public function tenancies(): array
    {
        return array_values(array_filter(
            $this->occupancies,
            static fn (OccupancyInput $occupancy): bool => $occupancy->kind === OccupancyKind::TENANCY
        ));
    }

    public function unit(string $unitKey): ?UnitInput
    {
        foreach ($this->units as $unit) {
            if ($unit->unitKey === $unitKey) {
                return $unit;
            }
        }

        return null;
    }

    public function prepaymentFor(string $occupancyKey): ?PrepaymentInput
    {
        foreach ($this->prepayments as $prepayment) {
            if ($prepayment->occupancyKey === $occupancyKey) {
                return $prepayment;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function unitKeys(): array
    {
        return array_map(static fn (UnitInput $unit): string => $unit->unitKey, $this->units);
    }
}
