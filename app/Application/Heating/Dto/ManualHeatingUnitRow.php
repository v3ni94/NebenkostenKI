<?php

declare(strict_types=1);

namespace App\Application\Heating\Dto;

use App\Domain\Money\Money;

/**
 * Zeile der Eingabemaske: eine Einheit mit den bereits erfassten Betraegen und
 * ihren Nutzungszeitraeumen.
 *
 * Die Betraege sind Ergebniswerte des Anwenders. Die Plattform rechnet sie
 * nicht nach.
 */
final readonly class ManualHeatingUnitRow
{
    /**
     * @param  list<ManualHeatingOccupancy>  $occupancies
     */
    public function __construct(
        public string $unitId,
        public string $unitLabel,
        public array $occupancies,
        public ?Money $heating = null,
        public ?Money $warmWater = null,
        public ?Money $co2Landlord = null,
        public ?Money $co2Tenant = null,
        public ?Money $other = null,
    ) {}

    public function hasAmounts(): bool
    {
        foreach ([$this->heating, $this->warmWater, $this->co2Landlord, $this->co2Tenant, $this->other] as $amount) {
            if ($amount instanceof Money && ! $amount->isZero()) {
                return true;
            }
        }

        return false;
    }

    public function hasTenantChange(): bool
    {
        return count($this->occupancies) > 1;
    }

    /**
     * Formularwert eines Betragsfeldes in deutscher Schreibweise.
     */
    public function formValue(?Money $amount): string
    {
        return $amount instanceof Money ? $amount->formatAmount() : '';
    }
}
