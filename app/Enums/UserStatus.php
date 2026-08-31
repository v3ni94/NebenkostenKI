<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kontostatus eines Kundennutzers.
 *
 * Gesperrte, geloeschte und unbestaetigte Konten erhalten keine Erinnerungen.
 */
enum UserStatus: string
{
    case UNBESTAETIGT = 'UNBESTAETIGT';
    case AKTIV = 'AKTIV';
    case GESPERRT = 'GESPERRT';
    case GELOESCHT = 'GELOESCHT';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::UNBESTAETIGT => 'E-Mail nicht bestätigt',
            self::AKTIV => 'Aktiv',
            self::GESPERRT => 'Gesperrt',
            self::GELOESCHT => 'Zur Löschung vorgemerkt',
        };
    }

    /**
     * Nur aktive und bestaetigte Konten duerfen Erinnerungen erhalten.
     */
    public function mayReceiveReminders(): bool
    {
        return $this === self::AKTIV;
    }
}
