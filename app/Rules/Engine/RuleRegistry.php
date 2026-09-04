<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use App\Rules\Definitions\BillingPeriodDeadlineRule;
use App\Rules\Definitions\CategoryChecksumRule;
use App\Rules\Definitions\CommercialTenancyRule;
use App\Rules\Definitions\CostOutsideBillingPeriodRule;
use App\Rules\Definitions\CreditNoteRule;
use App\Rules\Definitions\DuplicateCostRule;
use App\Rules\Definitions\ExternalHeatingChecksumRule;
use App\Rules\Definitions\HausgeldShareChecksumRule;
use App\Rules\Definitions\HeatingCaseBIncompleteRule;
use App\Rules\Definitions\HeatingCo2ShareStatusRule;
use App\Rules\Definitions\IncompleteMeasurementRule;
use App\Rules\Definitions\InvalidDenominatorRule;
use App\Rules\Definitions\MalwareScannerDisabledRule;
use App\Rules\Definitions\MissingDeliveryAddressRule;
use App\Rules\Definitions\MissingLandlordRule;
use App\Rules\Definitions\MissingPreviousYearCategoryRule;
use App\Rules\Definitions\MissingServicePeriodRule;
use App\Rules\Definitions\NotApportionableCostRule;
use App\Rules\Definitions\OtherOperatingCostsRule;
use App\Rules\Definitions\Paragraph35aLaborShareRule;
use App\Rules\Definitions\PaymentAmountMismatchRule;
use App\Rules\Definitions\PrepaymentOutsideTenancyRule;
use App\Rules\Definitions\PreviousYearDeviationRule;
use App\Rules\Definitions\PropertyTaxDuplicateRule;
use App\Rules\Definitions\RepeatedFinalizationRule;
use App\Rules\Definitions\TenancyCoverageGapRule;
use App\Rules\Definitions\TenancyOverlapRule;
use App\Rules\Definitions\UnusualSupplierOrAmountRule;
use DateTimeImmutable;

/**
 * Verzeichnis aller Pruefregeln.
 *
 * Das Verzeichnis enthaelt jede jemals ausgelieferte Regel. Regeln werden
 * nicht entfernt, sondern ueber validTo beendet, damit ein alter Regelstand
 * reproduzierbar bleibt.
 */
final class RuleRegistry
{
    /**
     * Alle bekannten Regeln in stabiler Reihenfolge.
     *
     * @return list<Rule>
     */
    public static function all(): array
    {
        return [
            new BillingPeriodDeadlineRule,
            new CostOutsideBillingPeriodRule,
            new MissingServicePeriodRule,
            new CategoryChecksumRule,
            new HausgeldShareChecksumRule,
            new ExternalHeatingChecksumRule,
            new DuplicateCostRule,
            new CreditNoteRule,
            new PropertyTaxDuplicateRule,
            new PreviousYearDeviationRule,
            new MissingPreviousYearCategoryRule,
            new UnusualSupplierOrAmountRule,
            new IncompleteMeasurementRule,
            new InvalidDenominatorRule,
            new PrepaymentOutsideTenancyRule,
            new TenancyOverlapRule,
            new TenancyCoverageGapRule,
            new CommercialTenancyRule,
            new MissingDeliveryAddressRule,
            new NotApportionableCostRule,
            new OtherOperatingCostsRule,
            new RepeatedFinalizationRule,
            new PaymentAmountMismatchRule,
            new HeatingCaseBIncompleteRule,
            new HeatingCo2ShareStatusRule,
            new Paragraph35aLaborShareRule,
            new MalwareScannerDisabledRule,
            new MissingLandlordRule,
        ];
    }

    /**
     * Regeln, die an einem Stichtag gueltig sind.
     *
     * @return list<Rule>
     */
    public static function effectiveOn(DateTimeImmutable $date): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (Rule $rule): bool => $rule->isEffectiveOn($date)
        ));
    }

    public static function find(string $code): ?Rule
    {
        foreach (self::all() as $rule) {
            if ($rule->code() === $code) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Alle Stichtage, an denen sich die Zusammensetzung aendert.
     *
     * @return list<string>
     */
    public static function validityBoundaries(): array
    {
        $boundaries = [];

        foreach (self::all() as $rule) {
            $boundaries[] = $rule->validFrom()->format('Y-m-d');

            $validTo = $rule->validTo();

            if ($validTo !== null) {
                $boundaries[] = $validTo->modify('+1 day')->format('Y-m-d');
            }
        }

        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries);

        return $boundaries;
    }
}
