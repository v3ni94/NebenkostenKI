<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * Eingabedaten der Eigenberechnung nach HeizkostenV (Fall B).
 *
 * Alle Felder sind bewusst optional, damit die Vollständigkeitsprüfung des
 * HeizkostenVCalculator die fehlenden Angaben konkret benennen kann. Fehlende
 * Werte bleiben null und werden niemals geschätzt (Grundsatz 5).
 */
final readonly class HeizkostenVInput
{
    /**
     * @param  array<string, string>  $heatedAreaByUnit  Einheit => beheizte Fläche in m²
     * @param  array<string, string>  $heatingConsumptionByUnit  Einheit => erfasster Heizverbrauch
     * @param  array<string, string>  $warmWaterConsumptionByUnit  Einheit => erfasster Warmwasserverbrauch
     * @param  int|null  $basicCostPercentage  Grundkostenanteil in Prozent (zulässiger Rahmen 30 bis 50)
     */
    public function __construct(
        public DatePeriodRange $period,
        public ?Money $totalFuelCost = null,
        public ?Money $operatingElectricityCost = null,
        public ?Money $maintenanceCost = null,
        public ?Money $meteringServiceCost = null,
        public ?Money $co2Cost = null,
        public ?int $basicCostPercentage = null,
        public array $heatedAreaByUnit = [],
        public array $heatingConsumptionByUnit = [],
        public array $warmWaterConsumptionByUnit = [],
        public ?string $fuelInventoryStart = null,
        public ?string $fuelInventoryEnd = null,
        public ?string $warmWaterEnergyShareMethod = null,
        public ?int $buildingCo2StepLevel = null,
        public ?string $buildingEmissionKgPerSqm = null,
    ) {}
}
