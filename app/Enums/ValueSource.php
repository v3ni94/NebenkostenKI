<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Herkunft eines uebernommenen Fachwertes.
 *
 * SOLL_ANNAHME kennzeichnet die ausdruecklich bestaetigte Annahme Ist gleich Soll.
 */
enum ValueSource: string
{
    case MIETVERTRAG = 'MIETVERTRAG';
    case VORJAHR = 'VORJAHR';
    case ZAHLUNGSUEBERSICHT = 'ZAHLUNGSUEBERSICHT';
    case ABLESEPROTOKOLL = 'ABLESEPROTOKOLL';
    case KI_EXTRAKTION = 'KI_EXTRAKTION';
    case MANUELL = 'MANUELL';
    case SOLL_ANNAHME = 'SOLL_ANNAHME';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::MIETVERTRAG => 'Mietvertrag',
            self::VORJAHR => 'Aus Vorjahr übernommen',
            self::ZAHLUNGSUEBERSICHT => 'Zahlungsübersicht',
            self::ABLESEPROTOKOLL => 'Ableseprotokoll',
            self::KI_EXTRAKTION => 'Automatisch ausgelesen',
            self::MANUELL => 'Manuelle Eingabe',
            self::SOLL_ANNAHME => 'Bestätigte Annahme Ist gleich Soll',
        };
    }
}
