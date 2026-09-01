<?php

declare(strict_types=1);

namespace App\Rules\Context;

use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Enums\Co2ShareStatus;
use App\Enums\HeatingSupplyCase;

/**
 * Heizkostenangaben eines Abrechnungslaufs.
 *
 * Fall A ist die externe Abrechnung, Fall B die Zentralheizung ohne externen
 * Abrechner, Fall C die dezentrale Versorgung (Pflichtenheft Abschnitt 12.3).
 *
 * In Fall B rechnet die Plattform nicht selbst. Der Anwender erfasst die
 * Betraege je Einheit manuell; sie werden unveraendert uebernommen.
 */
final readonly class RuleHeatingStatement
{
    /**
     * @param  array<string, Money>  $lineAmounts  Beteiligtenschluessel => Einzelbetrag
     * @param  list<string>  $unitKeysWithAmounts  Einheiten, fuer die Betraege erfasst sind
     */
    public function __construct(
        public string $key,
        public HeatingSupplyCase $supplyCase,
        public DatePeriodRange $period,
        public ?string $provider = null,
        public ?Money $totalAmount = null,
        public array $lineAmounts = [],
        public Co2ShareStatus $co2ShareStatus = Co2ShareStatus::UNBEKANNT,
        public ?Money $basicCost = null,
        public ?Money $consumptionCost = null,
        public bool $hasConsumptionValues = false,
        public ?Money $fuelStockValue = null,
        public ?Money $operatingCurrent = null,
        public ?Money $warmWaterShare = null,
        public ?Money $co2Cost = null,
        /**
         * Fall B: die Betraege wurden vom Anwender selbst ermittelt und
         * manuell erfasst. Die Plattform rechnet sie nicht nach.
         */
        public bool $manualEntry = false,
        public array $unitKeysWithAmounts = [],
    ) {}

    public function sumOfLines(): Money
    {
        return Money::sumOf($this->lineAmounts);
    }

    /**
     * Fehlende Angaben fuer eine vollstaendige Eigenberechnung nach Fall B.
     *
     * @return list<string>
     */
    public function missingFieldsForOwnCalculation(): array
    {
        $missing = [];

        if (! $this->basicCost instanceof Money) {
            $missing[] = 'Grundkosten';
        }

        if (! $this->consumptionCost instanceof Money) {
            $missing[] = 'Verbrauchskosten';
        }

        if (! $this->hasConsumptionValues) {
            $missing[] = 'Verbrauchswerte je Einheit';
        }

        if (! $this->fuelStockValue instanceof Money) {
            $missing[] = 'Brennstoffbestand';
        }

        if (! $this->operatingCurrent instanceof Money) {
            $missing[] = 'Betriebsstrom';
        }

        if (! $this->warmWaterShare instanceof Money) {
            $missing[] = 'Warmwasseranteil';
        }

        if (! $this->co2Cost instanceof Money) {
            $missing[] = 'CO2-Angaben';
        }

        return $missing;
    }
}
