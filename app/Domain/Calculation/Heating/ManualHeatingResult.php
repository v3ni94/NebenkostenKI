<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

use App\Domain\Allocation\DirectAssignmentKey;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Money\Money;

/**
 * Ergebnis der Pruefung manuell erfasster Heizkosten (Fall B).
 *
 * Die Pruefsumme ist ein Rueckgabewert, keine Exception: eine Abweichung ueber
 * der Toleranz blockiert die Finalisierung (blocksFinalization() = true), die
 * Zahlen bleiben lesbar und im Pruefbericht erklaerbar.
 *
 * Ist kein Gesamtbetrag erfasst, ist checksumAvailable false und der Hinweis
 * benennt, dass ohne Gesamtbetrag keine Gegenprobe moeglich ist.
 */
final readonly class ManualHeatingResult
{
    /**
     * @param  list<CheckFinding>  $findings
     */
    public function __construct(
        public ?Money $declaredTotal,
        public Money $sumOfRecordedAmounts,
        public Money $sumOfTenantAmounts,
        public ?Money $difference,
        public Money $tolerance,
        public bool $checksumAvailable,
        public bool $withinTolerance,
        public array $findings,
        public ?DirectAssignmentKey $allocationKey,
        public ?string $hint = null,
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
     * Verteilerschluessel zur Direktzuordnung je Einheit, sofern erfasste
     * Betraege vorliegen und die Pruefsumme nicht blockiert.
     */
    public function allocationKey(): ?DirectAssignmentKey
    {
        return $this->allocationKey;
    }
}
