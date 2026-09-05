<?php

declare(strict_types=1);

namespace App\Services\Pdf\Support;

use DateTimeInterface;

/**
 * Datumsformatierung für PDF-Ausgaben.
 *
 * Verbindliches Format ist TT.MM.JJJJ. Uhrzeiten werden in PDFs nicht
 * ausgegeben, weil sie fachlich bedeutungslos sind.
 */
final class GermanDate
{
    public static function format(?DateTimeInterface $date): string
    {
        return $date?->format('d.m.Y') ?? '';
    }

    /**
     * Datum mit Ersatztext, wenn keine Angabe vorliegt. Es wird niemals ein
     * Datum ergänzt oder geschätzt.
     */
    public static function formatOr(?DateTimeInterface $date, string $fallback): string
    {
        return $date instanceof DateTimeInterface ? $date->format('d.m.Y') : $fallback;
    }
}
