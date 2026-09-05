<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status der CO2-Kostenaufteilung. Unbekannt erzeugt eine Pruefaufgabe.
 */
enum Co2ShareStatus: string
{
    case ENTHALTEN = 'ENTHALTEN';
    case NICHT_ENTHALTEN = 'NICHT_ENTHALTEN';
    case UNBEKANNT = 'UNBEKANNT';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::ENTHALTEN => 'Bereits enthalten',
            self::NICHT_ENTHALTEN => 'Nicht enthalten',
            self::UNBEKANNT => 'Unbekannt',
        };
    }
}
