<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Grund einer Sperrung der Empfaengeradresse.
 */
enum EmailSuppressionReason: string
{
    case BOUNCE = 'BOUNCE';
    case BESCHWERDE = 'BESCHWERDE';
    case ABMELDUNG = 'ABMELDUNG';
    case MANUELL = 'MANUELL';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::BOUNCE => 'Dauerhafter Zustellfehler',
            self::BESCHWERDE => 'Beschwerde',
            self::ABMELDUNG => 'Abmeldung durch Empfänger',
            self::MANUELL => 'Manuelle Sperrung',
        };
    }
}
