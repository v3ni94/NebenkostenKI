<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * Angaben, die eine Eigenberechnung nach Heizkostenverordnung erfordern würde.
 *
 * Eine solche Eigenberechnung ist bewusst nicht Teil des Leistungsumfangs
 * (siehe HeizkostenVCalculator). Die Struktur dient ausschließlich der
 * Vollständigkeitsprüfung für Hinweistexte und Prüfaufgaben. Alle Felder sind
 * optional, damit die fehlenden Angaben konkret benannt werden können.
 * Fehlende Werte bleiben null und werden niemals geschätzt (Grundsatz 5).
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
