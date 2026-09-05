<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pruefstatus eines ausgelesenen Feldes nach Abschnitt 6.5.
 */
enum ExtractedFieldStatus: string
{
    case AUTOMATISCH_ERKANNT = 'AUTOMATISCH_ERKANNT';
    case BESTAETIGT = 'BESTAETIGT';
    case MANUELL_GEAENDERT = 'MANUELL_GEAENDERT';
    case VERWORFEN = 'VERWORFEN';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::AUTOMATISCH_ERKANNT => 'Automatisch erkannt',
            self::BESTAETIGT => 'Bestätigt',
            self::MANUELL_GEAENDERT => 'Manuell geändert',
            self::VERWORFEN => 'Verworfen',
        };
    }
}
