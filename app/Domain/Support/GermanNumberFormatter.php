<?php

declare(strict_types=1);

namespace App\Domain\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Deutsche Zahlenformatierung für Erklärungstexte und PDF-Ausgaben.
 *
 * Tausendertrennzeichen Punkt, Dezimaltrennzeichen Komma (z. B. 1.234,56).
 * Reine Formatierung, keine Berechnung: Rundung erfolgt ausschließlich zur
 * Darstellung mit RoundingMode::HALF_UP.
 */
final class GermanNumberFormatter
{
    /**
     * Formatiert einen Dezimalwert mit fester Anzahl Dezimalstellen.
     */
    public static function decimal(BigNumber|string|int $value, int $scale = 2): string
    {
        $decimal = BigDecimal::of($value)->toScale($scale, RoundingMode::HALF_UP);

        $negative = $decimal->isNegative();
        $digits = $decimal->abs()->toScale($scale, RoundingMode::UNNECESSARY)->__toString();

        $fraction = '';
        $integer = $digits;

        if (str_contains($digits, '.')) {
            [$integer, $fraction] = explode('.', $digits, 2);
        }

        $grouped = self::group($integer);
        $result = $scale > 0 ? $grouped.','.$fraction : $grouped;

        return $negative ? '-'.$result : $result;
    }

    /**
     * Formatiert einen Dezimalwert mit Maßeinheit, z. B. "72,50 m²".
     */
    public static function quantity(BigNumber|string|int $value, string $unit, int $scale = 2): string
    {
        $formatted = self::decimal($value, $scale);

        return $unit === '' ? $formatted : $formatted.' '.$unit;
    }

    private static function group(string $integerDigits): string
    {
        $chunks = [];
        $remaining = $integerDigits;

        while (strlen($remaining) > 3) {
            $chunks[] = substr($remaining, -3);
            $remaining = substr($remaining, 0, -3);
        }

        $chunks[] = $remaining;

        return implode('.', array_reverse($chunks));
    }
}
