<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bearbeitungsstand einer Pruefaufgabe.
 */
enum ValidationIssueStatus: string
{
    case OFFEN = 'OFFEN';
    case BESTAETIGT = 'BESTAETIGT';
    case KORRIGIERT = 'KORRIGIERT';
    case AKZEPTIERT = 'AKZEPTIERT';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::OFFEN => 'Offen',
            self::BESTAETIGT => 'Vom Nutzer bestätigt',
            self::KORRIGIERT => 'Korrigiert',
            self::AKZEPTIERT => 'Bewusst akzeptiert',
        };
    }
}
