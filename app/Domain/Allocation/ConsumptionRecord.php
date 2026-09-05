<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use Brick\Math\BigDecimal;

/**
 * Ein erfasster Verbrauchswert.
 *
 * Zwei Ausprägungen:
 * - forOccupancy(): Wert aus einer Zwischenablesung, bereits einem
 *   Nutzungszeitraum zugeordnet.
 * - forUnit(): Jahresverbrauch der Einheit ohne Zwischenablesung.
 */
final readonly class ConsumptionRecord
{
    private function __construct(
        public string $unitKey,
        public ?string $participantKey,
        public BigDecimal $value,
    ) {}

    public static function forOccupancy(string $unitKey, string $participantKey, BigDecimal|string|int $value): self
    {
        return new self($unitKey, $participantKey, self::toDecimal($value));
    }

    public static function forUnit(string $unitKey, BigDecimal|string|int $value): self
    {
        return new self($unitKey, null, self::toDecimal($value));
    }

    public function isOccupancyLevel(): bool
    {
        return $this->participantKey !== null;
    }

    private static function toDecimal(BigDecimal|string|int $value): BigDecimal
    {
        return BigDecimal::of(is_string($value) ? str_replace(',', '.', $value) : $value);
    }
}
