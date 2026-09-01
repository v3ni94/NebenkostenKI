<?php

declare(strict_types=1);

namespace App\Rules\Context;

use App\Domain\Calculation\Check\PreviousYearCategoryAmount;
use App\Domain\Period\DatePeriodRange;
use DateTimeImmutable;

/**
 * Vollstaendige, bereits validierte Eingabe der Regel-Engine.
 *
 * Der Kontext ist unveraenderlich und frei von Framework-Abhaengigkeiten.
 * Er wird entweder direkt im Test aufgebaut oder von
 * App\Rules\Engine\RuleContextFactory aus den Modellen eines
 * Abrechnungslaufs erzeugt.
 */
final readonly class RuleContext
{
    /**
     * @param  list<RuleCostItem>  $costItems
     * @param  list<RuleCategoryChecksum>  $categoryChecksums
     * @param  list<RuleHausgeldChecksum>  $hausgeldChecksums
     * @param  list<RuleHeatingStatement>  $heatingStatements
     * @param  list<RuleAllocationKey>  $allocationKeys
     * @param  list<RuleUnit>  $units
     * @param  list<RuleTenancy>  $tenancies
     * @param  list<RulePrepayment>  $prepayments
     * @param  list<PreviousYearCategoryAmount>  $previousYearCategories
     */
    public function __construct(
        public string $billingRunKey,
        public DatePeriodRange $billingPeriod,
        public DateTimeImmutable $preparedOn,
        public RuleTolerances $tolerances = new RuleTolerances,
        public array $costItems = [],
        public array $categoryChecksums = [],
        public array $hausgeldChecksums = [],
        public array $heatingStatements = [],
        public array $allocationKeys = [],
        public array $units = [],
        public array $tenancies = [],
        public array $prepayments = [],
        public array $previousYearCategories = [],
        public RuleSupplierHistory $supplierHistory = new RuleSupplierHistory,
        public RuleFinalizationState $finalizationState = new RuleFinalizationState,
        public RuleEnvironment $environment = new RuleEnvironment,
    ) {}

    /**
     * Kostenpositionen, die in die Mieterumlage eingehen.
     *
     * @return list<RuleCostItem>
     */
    public function apportionedCostItems(): array
    {
        return array_values(array_filter(
            $this->costItems,
            static fn (RuleCostItem $item): bool => $item->isApportioned()
        ));
    }

    public function tenancy(string $key): ?RuleTenancy
    {
        foreach ($this->tenancies as $tenancy) {
            if ($tenancy->key === $key) {
                return $tenancy;
            }
        }

        return null;
    }

    /**
     * Alle Mietverhaeltnisse einer Einheit.
     *
     * @return list<RuleTenancy>
     */
    public function tenanciesOfUnit(string $unitKey): array
    {
        return array_values(array_filter(
            $this->tenancies,
            static fn (RuleTenancy $tenancy): bool => $tenancy->unitKey === $unitKey
        ));
    }

    public function unitLabel(string $unitKey): string
    {
        foreach ($this->units as $unit) {
            if ($unit->key === $unitKey) {
                return $unit->label;
            }
        }

        return $unitKey;
    }
}
