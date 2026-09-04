<?php

declare(strict_types=1);

namespace Tests\Unit\Rules\Concerns;

use App\Domain\Calculation\Check\PreviousYearCategoryAmount;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Rules\Context\RuleAllocationKey;
use App\Rules\Context\RuleCategoryChecksum;
use App\Rules\Context\RuleContext;
use App\Rules\Context\RuleCostItem;
use App\Rules\Context\RuleEnvironment;
use App\Rules\Context\RuleFinalizationState;
use App\Rules\Context\RuleHausgeldChecksum;
use App\Rules\Context\RuleHeatingStatement;
use App\Rules\Context\RulePrepayment;
use App\Rules\Context\RuleSupplierHistory;
use App\Rules\Context\RuleTenancy;
use App\Rules\Context\RuleTolerances;
use App\Rules\Context\RuleUnit;
use App\Rules\Engine\Rule;
use App\Rules\Engine\RuleFinding;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Aufbau von Regelkontexten fuer die Unit Tests.
 *
 * Die Kontexte entstehen ohne Datenbank und ohne Framework. Alle Daten sind
 * frei erfunden.
 */
trait BuildsRuleContext
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
    protected function context(
        ?DatePeriodRange $billingPeriod = null,
        ?DateTimeImmutable $preparedOn = null,
        ?RuleTolerances $tolerances = null,
        array $costItems = [],
        array $categoryChecksums = [],
        array $hausgeldChecksums = [],
        array $heatingStatements = [],
        array $allocationKeys = [],
        array $units = [],
        array $tenancies = [],
        array $prepayments = [],
        array $previousYearCategories = [],
        ?RuleSupplierHistory $supplierHistory = null,
        ?RuleFinalizationState $finalizationState = null,
        ?RuleEnvironment $environment = null,
        bool $landlordPresent = true,
    ): RuleContext {
        return new RuleContext(
            'lauf-2025',
            $billingPeriod ?? DatePeriodRange::calendarYear(2025),
            $preparedOn ?? $this->day('2026-03-01'),
            $tolerances ?? new RuleTolerances,
            $costItems,
            $categoryChecksums,
            $hausgeldChecksums,
            $heatingStatements,
            $allocationKeys,
            $units,
            $tenancies,
            $prepayments,
            $previousYearCategories,
            $supplierHistory ?? new RuleSupplierHistory,
            $finalizationState ?? new RuleFinalizationState,
            $environment ?? new RuleEnvironment,
            $landlordPresent,
        );
    }

    protected function day(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso.' 00:00:00', new DateTimeZone('UTC'));
    }

    protected function euros(string $amount): Money
    {
        return Money::fromEuros($amount);
    }

    /**
     * @param  list<RuleFinding>  $findings
     * @return list<string>
     */
    protected function descriptions(array $findings): array
    {
        return array_map(static fn (RuleFinding $finding): string => $finding->description, $findings);
    }

    /**
     * @return list<RuleFinding>
     */
    protected function evaluate(Rule $rule, RuleContext $context): array
    {
        return $rule->evaluate($context);
    }
}
