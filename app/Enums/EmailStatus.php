<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Versandstatus einer Transaktionsmail. Keine vertraulichen Inhalte im Log.
 */
enum EmailStatus: string
{
    case WARTEND = 'WARTEND';
    case GESENDET = 'GESENDET';
    case FEHLGESCHLAGEN = 'FEHLGESCHLAGEN';
    case BOUNCED = 'BOUNCED';
    case UNTERDRUECKT = 'UNTERDRUECKT';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::WARTEND => 'Wartend',
            self::GESENDET => 'Gesendet',
            self::FEHLGESCHLAGEN => 'Fehlgeschlagen',
            self::BOUNCED => 'Unzustellbar',
            self::UNTERDRUECKT => 'Unterdrückt',
        };
    }
}
