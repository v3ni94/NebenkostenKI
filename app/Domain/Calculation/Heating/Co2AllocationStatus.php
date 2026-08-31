<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

/**
 * Status der CO2-Kostenaufteilung in einer externen Heizkostenabrechnung.
 *
 * INCLUDED  Die CO2-Kostenaufteilung ist in der Abrechnung bereits enthalten.
 * EXCLUDED  Die Abrechnung enthält keine CO2-Kostenaufteilung.
 * UNKNOWN   Unbekannter Status; erzeugt eine Prüfaufgabe
 *           (Pflichtenheft Abschnitt 12.3, Fall A).
 */
enum Co2AllocationStatus: string
{
    case INCLUDED = 'INCLUDED';
    case EXCLUDED = 'EXCLUDED';
    case UNKNOWN = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::INCLUDED => 'CO2-Kostenaufteilung enthalten',
            self::EXCLUDED => 'CO2-Kostenaufteilung nicht enthalten',
            self::UNKNOWN => 'CO2-Kostenaufteilung unklar',
        };
    }
}
