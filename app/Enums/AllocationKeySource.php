<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Herkunft eines Verteilerschluessels in der Vorschlagsreihenfolge
 * Mietvertrag, Vorjahr, Default. DEFAULT erzeugt immer einen Warnhinweis.
 */
enum AllocationKeySource: string
{
    case MIETVERTRAG = 'MIETVERTRAG';
    case VORJAHR = 'VORJAHR';
    case DEFAULT = 'DEFAULT';
    case MANUELL = 'MANUELL';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::MIETVERTRAG => 'Mietvertrag',
            self::VORJAHR => 'Aus Vorjahr übernommen',
            self::DEFAULT => 'Fachlicher Standardwert',
            self::MANUELL => 'Manuelle Eingabe',
        };
    }

    /**
     * Erfordert einen sichtbaren Warnhinweis in der Oberflaeche.
     */
    public function requiresWarning(): bool
    {
        return $this === self::DEFAULT;
    }
}
