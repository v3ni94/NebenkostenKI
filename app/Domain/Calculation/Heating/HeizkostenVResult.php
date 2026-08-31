<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

use App\Domain\Money\Money;

/**
 * Ergebnisstruktur der Eigenberechnung nach HeizkostenV (Fall B).
 *
 * Die Struktur ist vorbereitet, damit die spätere Freischaltung des Moduls
 * keine Änderung der Schnittstellen erfordert. Sie wird derzeit nicht
 * erzeugt; der HeizkostenVCalculator bricht vorher ab.
 */
final readonly class HeizkostenVResult
{
    /**
     * @param  array<string, Money>  $basicCostByUnit  Grundkostenanteil je Einheit
     * @param  array<string, Money>  $consumptionCostByUnit  Verbrauchskostenanteil je Einheit
     * @param  array<string, Money>  $warmWaterCostByUnit  Warmwasseranteil je Einheit
     * @param  array<string, Money>  $co2CostByUnit  CO2-Kostenanteil je Einheit nach Stufenmodell
     */
    public function __construct(
        public Money $totalCost,
        public Money $basicCostTotal,
        public Money $consumptionCostTotal,
        public Money $warmWaterCostTotal,
        public Money $co2CostTotal,
        public array $basicCostByUnit,
        public array $consumptionCostByUnit,
        public array $warmWaterCostByUnit,
        public array $co2CostByUnit,
    ) {}
}
