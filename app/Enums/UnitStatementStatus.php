<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status einer Mieterabrechnung.
 *
 * Ein finalisiertes Ergebnis wird niemals ueberschrieben. Korrekturen erzeugen
 * eine neue Version, die alte behaelt den Status ERSETZT.
 */
enum UnitStatementStatus: string
{
    case BERECHNET = 'BERECHNET';
    case VORSCHAU = 'VORSCHAU';
    case FINAL = 'FINAL';
    case ERSETZT = 'ERSETZT';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::BERECHNET => 'Berechnet',
            self::VORSCHAU => 'Vorschau erstellt',
            self::FINAL => 'Final',
            self::ERSETZT => 'Ersetzt',
        };
    }
}
