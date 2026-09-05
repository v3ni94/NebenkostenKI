<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Art des Mandanten. Jeder Nutzer erhaelt mindestens eine eigene Organisation.
 */
enum OrganizationType: string
{
    case PRIVATPERSON = 'PRIVATPERSON';
    case UNTERNEHMEN = 'UNTERNEHMEN';
    case HAUSVERWALTUNG = 'HAUSVERWALTUNG';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::PRIVATPERSON => 'Privatperson',
            self::UNTERNEHMEN => 'Unternehmen',
            self::HAUSVERWALTUNG => 'Hausverwaltung',
        };
    }
}
