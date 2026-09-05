<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Result;

use App\Domain\Money\Money;

/**
 * Nicht auf die erfassten Beteiligten verteilter Restanteil einer
 * Kostenposition.
 *
 * Entsteht, wenn die Summe der Zähler eines Verteilerschlüssels kleiner ist
 * als der Nenner, etwa bei MEA-Anteilen, die nur einen Teil der WEG
 * abbilden. Der Restanteil wird ausgewiesen und dem Eigentümer zugerechnet,
 * anstatt die übrigen Anteile stillschweigend zu erhöhen.
 */
final readonly class ResidualShare
{
    public function __construct(
        public string $costItemKey,
        public string $categoryKey,
        public string $categoryLabel,
        public Money $totalCost,
        public Money $amount,
        public string $explanation,
    ) {}
}
