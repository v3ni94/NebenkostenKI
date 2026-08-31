<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Weg;

use App\Domain\Money\Money;

/**
 * Eine aus der WEG-Einzelabrechnung ausgeschlossene Position mit Begründung.
 */
final readonly class ExcludedHausgeldPosition
{
    public function __construct(
        public string $positionKey,
        public string $label,
        public Money $unitShare,
        public HausgeldPositionKind $kind,
        public string $reason,
    ) {}
}
