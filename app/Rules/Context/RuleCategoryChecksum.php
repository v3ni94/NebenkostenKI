<?php

declare(strict_types=1);

namespace App\Rules\Context;

use App\Domain\Money\Money;

/**
 * Pruefsumme einer Kostenart: Summe der Einzelbelege gegen die ausgewiesene
 * Kategoriensumme.
 */
final readonly class RuleCategoryChecksum
{
    public function __construct(
        public string $categoryKey,
        public string $categoryLabel,
        public Money $declaredTotal,
        public Money $sumOfDocuments,
    ) {}

    public function difference(): Money
    {
        return $this->sumOfDocuments->minus($this->declaredTotal);
    }
}
