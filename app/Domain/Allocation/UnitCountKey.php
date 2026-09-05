<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Verteilung nach Einheiten (gleicher Anteil je Einheit).
 *
 * @see UnitCountKey::forUnits() erzeugt den Schlüssel mit Gewicht 1 je Einheit.
 */
final class UnitCountKey extends NumericAllocationKey
{
    public function type(): AllocationKeyType
    {
        return AllocationKeyType::UNITS;
    }

    /**
     * @param  list<string>  $unitKeys
     */
    public static function forUnits(array $unitKeys): self
    {
        $values = [];

        foreach ($unitKeys as $unitKey) {
            $values[$unitKey] = 1;
        }

        return new self($values);
    }

    protected function displayScale(): int
    {
        return 0;
    }
}
