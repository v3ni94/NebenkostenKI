<?php

declare(strict_types=1);

namespace App\Application\Heating;

use App\Domain\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Umrechnung einer Betragseingabe in Euro auf Cent.
 *
 * Grundsatz 8: Der Weg fuehrt ausschliesslich ueber BigDecimal. Es gibt KEINEN
 * Zwischenschritt ueber eine Fliesskommazahl, also keine Umwandlung in float,
 * keine kaufmaennische Rundung und keine Formatierungsfunktion auf dem
 * Eingabewert. Genau an dieser Stelle steckte kuerzlich ein Fehler; der Test
 * prueft die Quelle deshalb ausdruecklich auf solche Aufrufe.
 *
 * Zulaessig sind deutsche und einfache Schreibweisen:
 *  1.234,56  -> 123456 Cent
 *  1234,56   -> 123456 Cent
 *  1234.56   -> 123456 Cent
 *  1.234     -> 123400 Cent (Punkt als Tausendertrennzeichen)
 *  -12,50    -> -1250 Cent (Gutschrift)
 *
 * Mehr als zwei Dezimalstellen werden abgelehnt, weil jede Rundung eine
 * stille Veraenderung des erfassten Betrages waere.
 */
final class EuroAmountInput
{
    /**
     * Leerer Wert ergibt null. Ein unzulaessiger Wert loest eine
     * InvalidAmountException aus.
     */
    public static function parse(?string $value): ?Money
    {
        $decimal = self::decimal($value);

        return $decimal instanceof BigDecimal ? Money::fromEuros($decimal) : null;
    }

    /**
     * Wie parse(), gibt aber bei leerem Wert 0,00 EUR zurueck.
     */
    public static function parseOrZero(?string $value): Money
    {
        return self::parse($value) ?? Money::zero();
    }

    public static function isValid(?string $value): bool
    {
        try {
            self::parse($value);

            return true;
        } catch (InvalidAmountException) {
            return false;
        }
    }

    private static function decimal(?string $value): ?BigDecimal
    {
        if ($value === null) {
            return null;
        }

        $normalized = self::normalize($value);

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^-?\d+(\.\d{1,2})?$/', $normalized) !== 1) {
            throw InvalidAmountException::forValue($value);
        }

        try {
            return BigDecimal::of($normalized)->toScale(2);
        } catch (MathException) {
            throw InvalidAmountException::forValue($value);
        }
    }

    /**
     * Vereinheitlicht die Schreibweise zu einem Dezimalwert mit Punkt, ohne
     * jede Rechenoperation auf Fliesskommazahlen.
     */
    private static function normalize(string $value): string
    {
        $text = trim(str_replace(["\u{00A0}", "\u{202F}", ' ', 'EUR', '€'], '', $value));

        if ($text === '') {
            return '';
        }

        if (str_contains($text, ',')) {
            // Deutsche Schreibweise: Punkt ist Tausendertrennzeichen.
            return str_replace(',', '.', str_replace('.', '', $text));
        }

        // Ohne Komma gilt ein Punkt mit genau drei folgenden Ziffern als
        // Tausendertrennzeichen, sonst als Dezimaltrennzeichen.
        if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $text) === 1) {
            return str_replace('.', '', $text);
        }

        return $text;
    }
}
