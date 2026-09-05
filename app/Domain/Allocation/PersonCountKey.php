<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Verteilung nach Personenanzahl je Einheit (Stichtagsbetrachtung).
 *
 * Wechselt die Personenanzahl im Abrechnungszeitraum, ist der Schlüssel
 * Personentage (PersonDaysKey) fachlich vorzuziehen.
 */
final class PersonCountKey extends NumericAllocationKey
{
    public function type(): AllocationKeyType
    {
        return AllocationKeyType::PERSONS;
    }

    protected function displayScale(): int
    {
        return 0;
    }
}
