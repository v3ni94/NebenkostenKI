<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

/**
 * Normalisiert Dezimaleingaben aus Formularen.
 *
 * In Deutschland wird das Komma als Dezimaltrennzeichen erwartet. Die
 * Validierung und die Dezimalspalten arbeiten mit dem Punkt. Diese Klasse
 * wandelt deshalb "87,5" in "87.5" und entfernt Tausenderpunkte sowie
 * Leerzeichen.
 *
 * Es wird ausdruecklich nicht nach float gecastet. Der Wert bleibt eine
 * Zeichenkette und wird als solche in die DECIMAL-Spalte geschrieben
 * (ARCHITECTURE.md Grundsatz 8).
 */
final class DecimalInput
{
    /**
     * @param  array<string, mixed>  $daten
     * @param  list<string>  $felder
     * @return array<string, string|null>
     */
    public static function normalize(array $daten, array $felder): array
    {
        $ergebnis = [];

        foreach ($felder as $feld) {
            if (! array_key_exists($feld, $daten)) {
                continue;
            }

            $ergebnis[$feld] = self::value($daten[$feld]);
        }

        return $ergebnis;
    }

    public static function value(mixed $wert): ?string
    {
        if ($wert === null) {
            return null;
        }

        if (is_int($wert)) {
            return (string) $wert;
        }

        if (! is_string($wert)) {
            return null;
        }

        $text = trim($wert);

        if ($text === '') {
            return null;
        }

        $text = str_replace([' ', "\u{00A0}"], '', $text);

        // Tausenderpunkte nur entfernen, wenn zusaetzlich ein Komma vorkommt.
        // Sonst waere "1.500" nicht von "1,5" zu unterscheiden.
        if (str_contains($text, ',')) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        }

        return $text;
    }
}
