<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pruefstatus einer Kostenposition.
 *
 * Eine Sammelbestaetigung ist nur fuer konfliktfreie, hochkonfidente
 * Positionen zulaessig.
 */
enum CostItemStatus: string
{
    case VORGESCHLAGEN = 'VORGESCHLAGEN';
    case BESTAETIGT = 'BESTAETIGT';
    case VERWORFEN = 'VERWORFEN';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::VORGESCHLAGEN => 'Vorgeschlagen',
            self::BESTAETIGT => 'Bestätigt',
            self::VERWORFEN => 'Verworfen',
        };
    }
}
