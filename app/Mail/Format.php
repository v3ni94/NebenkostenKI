<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Support\Carbon;

/**
 * Deutsche Ausgabeformate der Transaktionsmails.
 *
 * Verbindlich (Masterprompt 0 Nummer 11, ARCHITECTURE.md 12):
 *   Datum    TT.MM.JJJJ
 *   Betraege 1.234,56 EUR, Grundlage ist immer ein Integer in Cent
 *
 * Es wird niemals ein Betrag aus einem Float gebildet und niemals ein Betrag
 * gerundet, der nicht bereits in Cent vorliegt.
 */
final class Format
{
    /**
     * Betrag aus Cent, zum Beispiel 123456 zu "1.234,56 EUR".
     */
    public static function betrag(int $cent): string
    {
        $vorzeichen = $cent < 0 ? '-' : '';
        $absolut = abs($cent);

        $euro = intdiv($absolut, 100);
        $rest = $absolut % 100;

        return sprintf(
            '%s%s,%02d EUR',
            $vorzeichen,
            number_format($euro, 0, ',', '.'),
            $rest
        );
    }

    /**
     * Datum als TT.MM.JJJJ.
     */
    public static function datum(Carbon|string|null $wert): string
    {
        if ($wert === null) {
            return '';
        }

        $datum = $wert instanceof Carbon ? $wert : Carbon::parse($wert);

        return $datum->format('d.m.Y');
    }
}
