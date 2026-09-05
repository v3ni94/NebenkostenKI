<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

use App\Domain\Allocation\DirectAssignmentKey;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Money\Money;

/**
 * Ergebnis der Prüfung einer externen Heizkostenabrechnung (Fall A).
 *
 * Die Prüfsumme ist ein RÜCKGABEWERT, keine Exception: eine Abweichung über
 * der übergebenen Toleranz blockiert die Finalisierung
 * (blocksFinalization() = true), die Werte bleiben aber lesbar und im
 * Prüfbericht erklärbar.
 */
final readonly class HeatingReconciliationResult
{
    /**
     * @param  list<CheckFinding>  $findings
     */
    public function __construct(
        public Money $totalAmount,
        public Money $sumOfParticipantAmounts,
        public Money $difference,
        public Money $tolerance,
        public bool $withinTolerance,
        public array $findings,
        public ?DirectAssignmentKey $allocationKey,
    ) {}

    public function blocksFinalization(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->blocksFinalization()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Der Verteilerschlüssel zur Direktzuordnung, sofern die Prüfsumme
     * innerhalb der Toleranz liegt.
     */
    public function allocationKey(): ?DirectAssignmentKey
    {
        return $this->allocationKey;
    }
}
