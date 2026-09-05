<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status einer geplanten oder versandten Erinnerung.
 */
enum ReminderStatus: string
{
    case GEPLANT = 'GEPLANT';
    case GESENDET = 'GESENDET';
    case UNTERDRUECKT = 'UNTERDRUECKT';
    case FEHLGESCHLAGEN = 'FEHLGESCHLAGEN';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::GEPLANT => 'Geplant',
            self::GESENDET => 'Gesendet',
            self::UNTERDRUECKT => 'Unterdrückt',
            self::FEHLGESCHLAGEN => 'Fehlgeschlagen',
        };
    }
}
