<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Verteilung nach beheizter Wohnfläche in Quadratmetern.
 *
 * Wird für Grundkosten der Heizung verwendet und ist bewusst getrennt von der
 * Wohnfläche, weil unbeheizte Flächen nicht einbezogen werden dürfen.
 */
final class HeatedLivingAreaKey extends NumericAllocationKey
{
    public function type(): AllocationKeyType
    {
        return AllocationKeyType::HEATED_LIVING_AREA;
    }
}
