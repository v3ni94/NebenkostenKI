<?php

declare(strict_types=1);

namespace App\Rules\Context;

use App\Domain\Money\Money;

/**
 * Pruefsumme einer Hausgeldabrechnung: Summe der Einzelanteile der Einheiten
 * gegen die ausgewiesene Gesamtsumme der Kostenart.
 */
final readonly class RuleHausgeldChecksum
{
    /**
     * @param  array<string, Money>  $sharesByUnit  Einheitenschluessel => Anteil
     */
    public function __construct(
        public string $positionLabel,
        public Money $declaredTotal,
        public array $sharesByUnit,
    ) {}

    public function sumOfShares(): Money
    {
        return Money::sumOf($this->sharesByUnit);
    }

    public function difference(): Money
    {
        return $this->sumOfShares()->minus($this->declaredTotal);
    }
}
