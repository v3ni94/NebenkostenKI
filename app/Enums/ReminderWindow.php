<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Erinnerungsfenster nach Abschnitt 17. Alle Termine in Europe/Berlin.
 *
 * Je Fenster und Objekt wird hoechstens eine Erinnerung versandt.
 */
enum ReminderWindow: string
{
    case Q1 = 'Q1';
    case Q2 = 'Q2';
    case Q3 = 'Q3';
    case DEZEMBER = 'DEZEMBER';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::Q1 => 'Erinnerung im ersten Quartal',
            self::Q2 => 'Erinnerung im zweiten Quartal',
            self::Q3 => 'Erinnerung im dritten Quartal',
            self::DEZEMBER => 'Fristerinnerung im Dezember',
        };
    }

    /**
     * Konfigurationsschluessel des Standarddatums in config/smartabrechnen.php.
     */
    public function configKey(): string
    {
        return match ($this) {
            self::Q1 => 'smartabrechnen.reminders.q1',
            self::Q2 => 'smartabrechnen.reminders.q2',
            self::Q3 => 'smartabrechnen.reminders.q3',
            self::DEZEMBER => 'smartabrechnen.reminders.december',
        };
    }
}
