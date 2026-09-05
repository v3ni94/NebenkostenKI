<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ergebnisart einer Mieterabrechnung.
 */
enum StatementResultKind: string
{
    case NACHZAHLUNG = 'NACHZAHLUNG';
    case GUTHABEN = 'GUTHABEN';
    case AUSGEGLICHEN = 'AUSGEGLICHEN';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::NACHZAHLUNG => 'Nachzahlung',
            self::GUTHABEN => 'Guthaben',
            self::AUSGEGLICHEN => 'Ausgeglichen',
        };
    }
}
