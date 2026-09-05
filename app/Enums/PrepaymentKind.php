<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Art der Vorauszahlung. Heizkosten koennen getrennt vereinbart sein.
 */
enum PrepaymentKind: string
{
    case BETRIEBSKOSTEN = 'BETRIEBSKOSTEN';
    case HEIZKOSTEN = 'HEIZKOSTEN';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::BETRIEBSKOSTEN => 'Betriebskostenvorauszahlung',
            self::HEIZKOSTEN => 'Heizkostenvorauszahlung',
        };
    }
}
