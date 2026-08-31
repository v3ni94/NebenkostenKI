<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Weg;

use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Money\Money;

/**
 * Ergebnis der Übernahme einer WEG-Einzelabrechnung.
 *
 * acceptedCostItems enthält ausschließlich die umlagefähigen Anteile der
 * konkreten Einheit als Direktzuordnung. excludedPositions enthält alle nach
 * Abschnitt 7.2 verbindlich ausgeschlossenen Werte mit Begründung.
 */
final readonly class HausgeldExtractionResult
{
    /**
     * @param  list<CostItemInput>  $acceptedCostItems
     * @param  list<ExcludedHausgeldPosition>  $excludedPositions
     * @param  list<CheckFinding>  $findings
     */
    public function __construct(
        public string $unitKey,
        public array $acceptedCostItems,
        public array $excludedPositions,
        public array $findings,
        public Money $acceptedTotal,
        public Money $excludedTotal,
        public bool $sufficientBreakdown,
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

    public function containsCategory(string $categoryKey): bool
    {
        foreach ($this->acceptedCostItems as $item) {
            if ($item->categoryKey === $categoryKey) {
                return true;
            }
        }

        return false;
    }

    public function acceptedItem(string $costItemKey): ?CostItemInput
    {
        foreach ($this->acceptedCostItems as $item) {
            if ($item->costItemKey === $costItemKey) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function excludedPositionKeys(): array
    {
        return array_map(
            static fn (ExcludedHausgeldPosition $position): string => $position->positionKey,
            $this->excludedPositions
        );
    }

    /**
     * @param  list<CostItemInput>  $additionalItems
     * @param  list<CheckFinding>  $additionalFindings
     */
    public function withAdditionalItems(array $additionalItems, array $additionalFindings = []): self
    {
        $accepted = array_merge($this->acceptedCostItems, $additionalItems);
        $total = $this->acceptedTotal;

        foreach ($additionalItems as $item) {
            $total = $total->plus($item->totalAmount);
        }

        return new self(
            $this->unitKey,
            array_values($accepted),
            $this->excludedPositions,
            array_values(array_merge($this->findings, $additionalFindings)),
            $total,
            $this->excludedTotal,
            $this->sufficientBreakdown
        );
    }
}
