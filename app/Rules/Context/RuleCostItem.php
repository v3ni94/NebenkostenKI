<?php

declare(strict_types=1);

namespace App\Rules\Context;

use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Enums\ApportionmentStatus;
use App\Enums\Paragraph35aType;

/**
 * Eine Kostenposition, wie die Regel-Engine sie sieht.
 *
 * Die Angaben sind bereits validierte Eingabedaten. Fehlende Werte bleiben
 * null und werden von den Regeln als Pruefaufgabe gemeldet, niemals geschaetzt.
 */
final readonly class RuleCostItem
{
    public function __construct(
        public string $key,
        public string $description,
        public string $categoryKey,
        public string $categoryLabel,
        public Money $amount,
        public ?DatePeriodRange $servicePeriod = null,
        public ?string $supplier = null,
        public ?string $invoiceNumber = null,
        public ?string $documentDate = null,
        public ?string $fingerprint = null,
        public ApportionmentStatus $apportionmentStatus = ApportionmentStatus::UMLAGEFAEHIG,
        public bool $excludedFromApportionment = false,
        public ?string $apportionmentOverrideReason = null,
        public bool $isCreditNote = false,
        public ?string $relatedInvoiceKey = null,
        public Paragraph35aType $paragraph35aType = Paragraph35aType::NONE,
        public ?int $laborShareCent = null,
        public bool $isPropertyTax = false,
        public bool $isOtherOperatingCosts = false,
        public bool $contractBasisRecognized = false,
    ) {}

    /**
     * Die Position geht in die Mieterumlage ein.
     */
    public function isApportioned(): bool
    {
        return ! $this->excludedFromApportionment;
    }
}
