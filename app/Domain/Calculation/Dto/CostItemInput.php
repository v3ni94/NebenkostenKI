<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Dto;

use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * Eine zu verteilende Kostenposition.
 *
 * Ein negativer Betrag ist zulässig und bildet eine Gutschrift ab; sie wird
 * mit derselben Gewichtung verteilt wie die zugehörige Kostenart.
 *
 * Umlagefähigkeit: Positionen mit Status NOT_ALLOCABLE oder REVIEW_REQUIRED
 * werden standardmäßig NICHT auf Mieter umgelegt. Eine bewusste Einbeziehung
 * ist ausschließlich über inclusionOverrideReason möglich; das Ergebnis
 * kennzeichnet diese Zeile ausdrücklich. Die Engine trifft keine juristische
 * Freigabe.
 *
 * § 35a EStG: laborShare wird nur übernommen, wenn der Lohnanteil tatsächlich
 * ausgewiesen ist. Ist laborShareDisclosed false, bleibt der Betrag null und
 * die Zeile wird als "Lohnanteil nicht ausgewiesen" gekennzeichnet.
 */
final readonly class CostItemInput
{
    public function __construct(
        public string $costItemKey,
        public string $categoryKey,
        public string $categoryLabel,
        public Money $totalAmount,
        public string $allocationKeyRef,
        public AllocabilityStatus $allocabilityStatus = AllocabilityStatus::ALLOCABLE,
        public ?DatePeriodRange $servicePeriod = null,
        public ?string $inclusionOverrideReason = null,
        public TaxBenefitCategory $taxBenefitCategory = TaxBenefitCategory::NONE,
        public ?Money $laborShare = null,
        public bool $laborShareDisclosed = true,
        public ?string $documentReference = null,
        public bool $isCreditNote = false,
    ) {}

    /**
     * Wird die Position auf Mieter umgelegt?
     *
     * Nicht umlagefähige und prüfpflichtige Positionen nur bei ausdrücklich
     * begründeter Einbeziehung.
     */
    public function isIncludedInTenantAllocation(): bool
    {
        if ($this->allocabilityStatus->isAllocatedByDefault()) {
            return true;
        }

        return $this->inclusionOverrideReason !== null && trim($this->inclusionOverrideReason) !== '';
    }

    public function isIncludedByOverride(): bool
    {
        return ! $this->allocabilityStatus->isAllocatedByDefault() && $this->isIncludedInTenantAllocation();
    }

    /**
     * Begünstigter Lohnanteil nach § 35a EStG, sofern ausgewiesen.
     */
    public function benefitedLaborShare(): ?Money
    {
        if (! $this->taxBenefitCategory->isBenefited()) {
            return null;
        }

        if (! $this->laborShareDisclosed) {
            return null;
        }

        return $this->laborShare;
    }

    /**
     * Begünstigte Kostenart, deren Lohnanteil nicht ausgewiesen ist.
     */
    public function hasUndisclosedLaborShare(): bool
    {
        return $this->taxBenefitCategory->isBenefited() && ! $this->laborShareDisclosed;
    }
}
