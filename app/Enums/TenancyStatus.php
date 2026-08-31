<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status eines Mietverhaeltnisses im Konto des Vermieters.
 */
enum TenancyStatus: string
{
    case ENTWURF = 'ENTWURF';
    case AKTIV = 'AKTIV';
    case BEENDET = 'BEENDET';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::ENTWURF => 'Entwurf',
            self::AKTIV => 'Aktiv',
            self::BEENDET => 'Beendet',
        };
    }
}
