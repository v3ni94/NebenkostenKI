<?php

declare(strict_types=1);

namespace App\Rules\Context;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Ein Verteilerschluessel mit Nenner und den Zaehlerwerten je Einheit.
 *
 * Dezimalwerte werden als Zeichenkette uebergeben und mit brick/math
 * verarbeitet, niemals als binaerer Float.
 */
final readonly class RuleAllocationKey
{
    /**
     * @param  array<string, string|null>  $numerators  Einheitenschluessel => Zaehlerwert
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $keyType,
        public ?string $denominator = null,
        public array $numerators = [],
    ) {}

    /**
     * Einheiten ohne hinterlegten Zaehlerwert.
     *
     * @return list<string>
     */
    public function unitsWithoutValue(): array
    {
        $missing = [];

        foreach ($this->numerators as $unitKey => $value) {
            if ($value === null || trim($value) === '') {
                $missing[] = (string) $unitKey;
            }
        }

        return $missing;
    }

    /**
     * Einheiten mit negativem Zaehlerwert.
     *
     * @return list<string>
     */
    public function unitsWithNegativeValue(): array
    {
        $negative = [];

        foreach ($this->numerators as $unitKey => $value) {
            $decimal = self::toDecimal($value);

            if ($decimal instanceof BigDecimal && $decimal->isNegative()) {
                $negative[] = (string) $unitKey;
            }
        }

        return $negative;
    }

    public function denominatorValue(): ?BigDecimal
    {
        return self::toDecimal($this->denominator);
    }

    private static function toDecimal(?string $value): ?BigDecimal
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return BigDecimal::of(str_replace(',', '.', trim($value)));
        } catch (MathException) {
            return null;
        }
    }
}
