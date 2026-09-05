<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Art des Mietverhaeltnisses.
 *
 * Gewerbe ist im Datenmodell vorbereitet, wird aber nicht stillschweigend
 * nach Wohnraummietrecht abgerechnet und blockiert die Finalisierung.
 */
enum TenancyKind: string
{
    case WOHNRAUM = 'WOHNRAUM';
    case GEWERBE = 'GEWERBE';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::WOHNRAUM => 'Wohnraum',
            self::GEWERBE => 'Gewerbe',
        };
    }

    /**
     * Gewerbe ist bis zur gesonderten Umsetzung nicht finalisierbar.
     */
    public function allowsAutomaticFinalization(): bool
    {
        return $this === self::WOHNRAUM;
    }
}
