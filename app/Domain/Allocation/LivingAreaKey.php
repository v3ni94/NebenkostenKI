<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Verteilung nach Wohnfläche in Quadratmetern.
 *
 * Beispiel-Erklärungstext: "Wohnfläche 72,50 m² von 310,00 m²".
 */
final class LivingAreaKey extends NumericAllocationKey
{
    public function type(): AllocationKeyType
    {
        return AllocationKeyType::LIVING_AREA;
    }
}
