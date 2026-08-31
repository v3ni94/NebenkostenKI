<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Result;

use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;
use App\Domain\Period\TimeFactor;

/**
 * Eine Zeile der Mieterabrechnung (Pflichtenheft Abschnitt 11.1).
 *
 * Enthält alles, was das PDF für einen nachvollziehbaren Rechenweg braucht:
 * Kostenart, Gesamtkosten, angewandter Schlüssel als Text, Zähler und Nenner,
 * Zeitfaktor mit Tagesangabe, Anteil in Cent, Rundungsausgleich,
 * Umlagestatus und § 35a Lohnanteil.
 */
final readonly class StatementLine
{
    public function __construct(
        public string $costItemKey,
        public string $categoryKey,
        public string $categoryLabel,
        public Money $totalCost,
        public string $allocationKeyLabel,
        public string $allocationExplanation,
        public string $numerator,
        public string $denominator,
        public TimeFactor $timeFactor,
        public Money $share,
        public int $roundingAdjustmentCent,
        public AllocabilityStatus $allocabilityStatus,
        public bool $includedByOverride = false,
        public ?string $inclusionOverrideReason = null,
        public TaxBenefitCategory $taxBenefitCategory = TaxBenefitCategory::NONE,
        public ?Money $taxBenefitLaborShare = null,
        public bool $laborShareDisclosed = true,
        public bool $substituteDistributionConfirmed = false,
        public ?string $documentReference = null,
    ) {}

    /**
     * Vollständiger Rechenweg als Text für die PDF-Spalte "Berechnung".
     */
    public function calculationExplanation(): string
    {
        $parts = [$this->allocationExplanation];

        if (! $this->timeFactor->includedInAllocationKey) {
            $parts[] = 'Zeitanteil '.$this->timeFactor->explanation();
        } else {
            $parts[] = $this->timeFactor->explanation();
        }

        return implode(', ', $parts);
    }

    public function isCreditNote(): bool
    {
        return $this->totalCost->isNegative();
    }

    public function hasUndisclosedLaborShare(): bool
    {
        return $this->taxBenefitCategory->isBenefited() && ! $this->laborShareDisclosed;
    }
}
