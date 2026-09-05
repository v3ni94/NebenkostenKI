<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Calculation\Check\InvoiceReference;
use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;
use App\Enums\ApportionmentStatus;
use App\Enums\Paragraph35aType;
use App\Rules\Context\RuleCostItem;

/**
 * Abbildung der Regelkontexte auf die Eingabetypen der Domain.
 *
 * Die Regel-Engine dupliziert keine Fachlogik. Dublettenerkennung und
 * Vorjahresvergleich liegen in App\Domain\Calculation\Check; dieser Mapper
 * stellt nur die dort erwarteten Eingabeobjekte her.
 */
final class DomainInputMapper
{
    /**
     * @param  list<RuleCostItem>  $items
     * @return list<InvoiceReference>
     */
    public static function toInvoiceReferences(array $items): array
    {
        return array_map(
            static fn (RuleCostItem $item): InvoiceReference => new InvoiceReference(
                $item->key,
                $item->description,
                $item->amount,
                $item->supplier,
                $item->invoiceNumber,
                $item->documentDate,
                $item->fingerprint,
                $item->isCreditNote,
                $item->relatedInvoiceKey,
            ),
            $items
        );
    }

    /**
     * @param  list<RuleCostItem>  $items
     * @return list<CostItemInput>
     */
    public static function toCostItemInputs(array $items): array
    {
        return array_map(
            static fn (RuleCostItem $item): CostItemInput => new CostItemInput(
                $item->key,
                $item->categoryKey,
                $item->categoryLabel,
                $item->amount,
                $item->categoryKey,
                self::allocability($item->apportionmentStatus),
                $item->servicePeriod,
                $item->apportionmentOverrideReason,
                self::taxBenefit($item->paragraph35aType),
                $item->laborShareCent === null ? null : Money::fromCents($item->laborShareCent),
                $item->laborShareCent !== null,
                null,
                $item->isCreditNote,
            ),
            $items
        );
    }

    public static function allocability(ApportionmentStatus $status): AllocabilityStatus
    {
        return match ($status) {
            ApportionmentStatus::UMLAGEFAEHIG => AllocabilityStatus::ALLOCABLE,
            ApportionmentStatus::NICHT_UMLAGEFAEHIG => AllocabilityStatus::NOT_ALLOCABLE,
            ApportionmentStatus::PRUEFPFLICHTIG => AllocabilityStatus::REVIEW_REQUIRED,
        };
    }

    public static function taxBenefit(Paragraph35aType $type): TaxBenefitCategory
    {
        return match ($type) {
            Paragraph35aType::NONE => TaxBenefitCategory::NONE,
            Paragraph35aType::HAUSHALTSNAHE_DIENSTLEISTUNG => TaxBenefitCategory::HOUSEHOLD_SERVICE,
            Paragraph35aType::HANDWERKERLEISTUNG => TaxBenefitCategory::CRAFTSMAN_SERVICE,
        };
    }
}
