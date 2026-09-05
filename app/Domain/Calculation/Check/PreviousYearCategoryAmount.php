<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Check;

use App\Domain\Money\Money;

/**
 * Vorjahresbetrag einer Kostenart für den Plausibilitätsvergleich.
 */
final readonly class PreviousYearCategoryAmount
{
    public function __construct(
        public string $categoryKey,
        public string $categoryLabel,
        public Money $amount,
    ) {}
}
