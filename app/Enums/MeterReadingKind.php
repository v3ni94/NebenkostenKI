<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Art einer Ablesung.
 *
 * Fehlt bei einem Nutzerwechsel die Zwischenablesung, erfolgt keine stille
 * Schaetzung, sondern eine Pruefaufgabe.
 */
enum MeterReadingKind: string
{
    case ANFANGSSTAND = 'ANFANGSSTAND';
    case ENDSTAND = 'ENDSTAND';
    case ZWISCHENABLESUNG = 'ZWISCHENABLESUNG';
    case NUTZERWECHSEL = 'NUTZERWECHSEL';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::ANFANGSSTAND => 'Anfangsstand',
            self::ENDSTAND => 'Endstand',
            self::ZWISCHENABLESUNG => 'Zwischenablesung',
            self::NUTZERWECHSEL => 'Ablesung bei Nutzerwechsel',
        };
    }
}
