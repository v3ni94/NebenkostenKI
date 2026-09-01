<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Dto;

use App\Enums\ApportionmentStatus;
use App\Enums\CostItemSource;
use App\Enums\Paragraph35aType;
use Illuminate\Support\Carbon;

/**
 * Vorschlag fuer eine Kostenposition.
 *
 * Ein Vorschlag ist niemals bestaetigt. Er wird als CostItem mit dem Status
 * VORGESCHLAGEN geschrieben und erst durch eine ausdrueckliche
 * Nutzerentscheidung bestaetigt (Abschnitt 6.5 und Schritt 6).
 *
 * Betraege ausschliesslich in Cent. Der Lohnanteil nach § 35a EStG wird nur
 * uebernommen, wenn er im Beleg ausdruecklich beziffert ist.
 */
final readonly class ProposedCostItem
{
    public function __construct(
        public string $proposalKey,
        public string $description,
        public int $amountCent,
        public ?string $documentId = null,
        public ?string $supplierName = null,
        public ?string $invoiceNumber = null,
        public ?Carbon $documentDate = null,
        public ?Carbon $servicePeriodStart = null,
        public ?Carbon $servicePeriodEnd = null,
        public ?string $categoryCode = null,
        public ApportionmentStatus $apportionmentStatus = ApportionmentStatus::PRUEFPFLICHTIG,
        public bool $excludedFromApportionment = false,
        public Paragraph35aType $paragraph35aType = Paragraph35aType::NONE,
        public ?int $laborShareCent = null,
        public ?string $confidence = null,
        public ?int $sourcePage = null,
        public ?string $sourceExcerpt = null,
        public bool $isHeatingCost = false,
        public bool $isWarmWaterCost = false,
        public ?string $directUnitId = null,
        public CostItemSource $source = CostItemSource::KI_EXTRAKTION,
        public ?string $sourceLabel = null,
    ) {}

    public function withCategoryCode(?string $categoryCode): self
    {
        return new self(
            $this->proposalKey,
            $this->description,
            $this->amountCent,
            $this->documentId,
            $this->supplierName,
            $this->invoiceNumber,
            $this->documentDate,
            $this->servicePeriodStart,
            $this->servicePeriodEnd,
            $categoryCode,
            $this->apportionmentStatus,
            $this->excludedFromApportionment,
            $this->paragraph35aType,
            $this->laborShareCent,
            $this->confidence,
            $this->sourcePage,
            $this->sourceExcerpt,
            $this->isHeatingCost,
            $this->isWarmWaterCost,
            $this->directUnitId,
            $this->source,
            $this->sourceLabel,
        );
    }

    public function withDirectUnit(?string $unitId): self
    {
        return new self(
            $this->proposalKey,
            $this->description,
            $this->amountCent,
            $this->documentId,
            $this->supplierName,
            $this->invoiceNumber,
            $this->documentDate,
            $this->servicePeriodStart,
            $this->servicePeriodEnd,
            $this->categoryCode,
            $this->apportionmentStatus,
            $this->excludedFromApportionment,
            $this->paragraph35aType,
            $this->laborShareCent,
            $this->confidence,
            $this->sourcePage,
            $this->sourceExcerpt,
            $this->isHeatingCost,
            $this->isWarmWaterCost,
            $unitId,
            $this->source,
            $this->sourceLabel,
        );
    }
}
