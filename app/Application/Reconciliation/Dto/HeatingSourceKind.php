<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Dto;

/**
 * Quellen der Heizkosten-Reconciliation nach Abschnitt 7.4.
 */
enum HeatingSourceKind: string
{
    case HAUSGELD_HEIZKOSTEN = 'HAUSGELD_HEIZKOSTEN';
    case EXTERNE_EINZELABRECHNUNG = 'EXTERNE_EINZELABRECHNUNG';
    case BRENNSTOFFRECHNUNG = 'BRENNSTOFFRECHNUNG';

    public function label(): string
    {
        return match ($this) {
            self::HAUSGELD_HEIZKOSTEN => 'Hausgeldabrechnung, Heizkosten',
            self::EXTERNE_EINZELABRECHNUNG => 'Externe Heizkostenabrechnung',
            self::BRENNSTOFFRECHNUNG => 'Brennstoffrechnung',
        };
    }
}
