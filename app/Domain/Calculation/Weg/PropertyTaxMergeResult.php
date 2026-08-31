<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Weg;

use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Result\CheckFinding;

/**
 * Ergebnis der Grundsteuerprüfung.
 *
 * added = false bedeutet: die Grundsteuer wurde NICHT addiert. Der Grund
 * steht in den Prüfergebnissen, etwa eine mögliche Dublette gegenüber der
 * Hausgeldabrechnung oder ein unbestätigter Teilzeitraum.
 */
final readonly class PropertyTaxMergeResult
{
    /**
     * @param  list<CheckFinding>  $findings
     */
    public function __construct(
        public bool $added,
        public ?CostItemInput $costItem,
        public array $findings,
        public bool $possibleDuplicate,
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
     * @return list<CostItemInput>
     */
    public function costItems(): array
    {
        return $this->costItem instanceof CostItemInput ? [$this->costItem] : [];
    }
}
