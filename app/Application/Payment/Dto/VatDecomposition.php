<?php

declare(strict_types=1);

namespace App\Application\Payment\Dto;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Zerlegung eines Bruttobetrags in Netto und Umsatzsteuer (Abschnitt 1.3,
 * ADR-010).
 *
 * VERBINDLICHE RECHENWEISE
 *
 *   Netto  = Brutto / (1 + Satz / 100), kaufmaennisch auf Cent gerundet
 *   Steuer = Brutto - Netto
 *
 * Die Steuer wird ausdruecklich als Differenz gebildet und nicht getrennt
 * gerundet. Damit ergibt Netto plus Steuer in jedem Fall exakt den
 * Bruttobetrag; eine Rundungsdifferenz von einem Cent liegt immer in der
 * Steuer. Gerechnet wird mit brick/math, niemals mit binaeren Floats
 * (Grundsatz 8).
 */
final readonly class VatDecomposition
{
    private function __construct(
        public int $grossCent,
        public int $netCent,
        public int $taxCent,
        public string $ratePercent,
    ) {}

    public static function fromGross(int $grossCent, string|int $ratePercent): self
    {
        $rate = self::normalizeRate($ratePercent);
        $net = self::netOf($grossCent, $rate);

        return new self($grossCent, $net, $grossCent - $net, $rate);
    }

    /**
     * Nettobetrag zu einem Bruttobetrag, kaufmaennisch auf Cent gerundet.
     */
    public static function netOf(int $grossCent, string|int $ratePercent): int
    {
        $rate = BigDecimal::of(self::normalizeRate($ratePercent));
        $divisor = BigDecimal::one()->plus($rate->dividedBy(100, 10, RoundingMode::HALF_UP));

        if ($divisor->isZero()) {
            return $grossCent;
        }

        return BigDecimal::of($grossCent)
            ->dividedBy($divisor, 0, RoundingMode::HALF_UP)
            ->toInt();
    }

    private static function normalizeRate(string|int $ratePercent): string
    {
        if (is_int($ratePercent)) {
            return (string) $ratePercent;
        }

        $normalized = trim(str_replace(',', '.', $ratePercent));

        return $normalized === '' ? '0' : $normalized;
    }
}
