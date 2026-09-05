<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Result;

use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Money\Money;

/**
 * Eine aus der Mieterumlage ausgeschlossene Kostenposition.
 *
 * Wird in der Eigentümerübersicht ausgewiesen, damit nachvollziehbar bleibt,
 * welche Kosten der Eigentümer selbst trägt und aus welchem Grund.
 */
final readonly class ExcludedCost
{
    public function __construct(
        public string $costItemKey,
        public string $categoryKey,
        public string $categoryLabel,
        public Money $amount,
        public AllocabilityStatus $allocabilityStatus,
        public string $reason,
        public ?string $documentReference = null,
    ) {}
}
