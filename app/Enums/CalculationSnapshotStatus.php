<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status eines Calculation Snapshots.
 *
 * Ein bezahlter Snapshot wird gesperrt und niemals ueberschrieben. Korrekturen
 * erzeugen einen neuen Snapshot, der alte bleibt als ERSETZT reproduzierbar.
 */
enum CalculationSnapshotStatus: string
{
    case BERECHNET = 'BERECHNET';
    case GESPERRT = 'GESPERRT';
    case ERSETZT = 'ERSETZT';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::BERECHNET => 'Berechnet',
            self::GESPERRT => 'Gesperrt',
            self::ERSETZT => 'Ersetzt',
        };
    }
}
