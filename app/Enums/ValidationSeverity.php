<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Schweregrad eines Pruefergebnisses nach Abschnitt 9 Schritt 9.
 *
 * BLOCKER verhindert die Finalisierung, WARNUNG erfordert eine ausdrueckliche
 * Nutzerentscheidung.
 */
enum ValidationSeverity: string
{
    case BLOCKER = 'BLOCKER';
    case WARNUNG = 'WARNUNG';
    case HINWEIS = 'HINWEIS';
    case BESTANDEN = 'BESTANDEN';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::BLOCKER => 'Blockiert die Abrechnung',
            self::WARNUNG => 'Warnung',
            self::HINWEIS => 'Hinweis',
            self::BESTANDEN => 'Bestanden',
        };
    }

    /**
     * Verhindert die automatische Finalisierung.
     */
    public function blocksFinalization(): bool
    {
        return $this === self::BLOCKER;
    }
}
