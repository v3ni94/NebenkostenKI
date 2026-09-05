<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Weg;

use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Period\DatePeriodRange;

/**
 * Grundsteuer nur ergänzen, wenn sie nicht bereits enthalten ist
 * (Pflichtenheft Abschnitt 7.3).
 *
 * Verbindliche Regeln:
 * - Ist die Grundsteuer bereits in der Hausgeldabrechnung oder einer anderen
 *   Kostenliste enthalten, wird KEINE Addition vorgenommen. Es entsteht ein
 *   Prüfergebnis (mögliche Dublette).
 * - Ist die Grundsteuer eindeutig separat und der Einheit direkt zugeordnet,
 *   wird sie als direkte Betriebskostenposition übernommen.
 * - Teilzeiträume und Eigentumswechsel werden nicht geraten. Weicht der
 *   Zeitraum des Bescheids vom Abrechnungszeitraum ab, ist eine
 *   ausdrückliche Bestätigung erforderlich.
 */
final class PropertyTaxMerger
{
    /**
     * Kategorieschlüssel der Grundsteuer in der Domain.
     */
    public const string CATEGORY_KEY = 'GRUNDSTEUER';

    /**
     * @param  list<string>  $existingCategoryKeys  bereits erfasste Kategorien anderer Kostenquellen
     */
    public function merge(
        PropertyTaxInput $propertyTax,
        HausgeldStatementInput $statement,
        DatePeriodRange $billingPeriod,
        string $allocationKeyRef,
        array $existingCategoryKeys = [],
    ): PropertyTaxMergeResult {
        $findings = [];

        $inStatement = $statement->containsPropertyTax();
        $inOtherSource = in_array(self::CATEGORY_KEY, $existingCategoryKeys, true);

        if ($inStatement || $inOtherSource) {
            $findings[] = CheckFinding::warning(
                CheckCode::PROPERTY_TAX_POSSIBLE_DUPLICATE,
                sprintf(
                    'Die Grundsteuer über %s wurde NICHT zusätzlich angesetzt, weil sie bereits %s enthalten ist. '
                    .'Bitte prüfen, welche Quelle maßgeblich ist.',
                    $propertyTax->annualAmount->format(),
                    $inStatement ? 'in der Hausgeldabrechnung' : 'in einer anderen Kostenliste'
                ),
                [
                    'unitKey' => $propertyTax->unitKey,
                    'amountCent' => $propertyTax->annualAmount->cents,
                    'source' => $inStatement ? 'HAUSGELD' : 'ANDERE_KOSTENLISTE',
                ]
            );

            return new PropertyTaxMergeResult(false, null, $findings, true);
        }

        if (! $propertyTax->directlyAssignedToUnit) {
            $findings[] = CheckFinding::warning(
                CheckCode::PROPERTY_TAX_POSSIBLE_DUPLICATE,
                'Der Grundsteuerbescheid ist nicht eindeutig der Einheit zugeordnet. Eine Übernahme ist erst nach '
                .'Zuordnung und Bestätigung möglich.',
                ['unitKey' => $propertyTax->unitKey]
            );

            return new PropertyTaxMergeResult(false, null, $findings, false);
        }

        if (! $billingPeriod->equals($propertyTax->period) && ! $propertyTax->periodConfirmed) {
            $findings[] = CheckFinding::warning(
                CheckCode::COST_OUTSIDE_BILLING_PERIOD,
                sprintf(
                    'Der Zeitraum des Grundsteuerbescheids (%s) entspricht nicht dem Abrechnungszeitraum (%s). '
                    .'Teilzeiträume und Eigentumswechsel werden nicht geschätzt; bitte den Betrag bestätigen.',
                    $propertyTax->period->format(),
                    $billingPeriod->format()
                ),
                ['unitKey' => $propertyTax->unitKey]
            );

            return new PropertyTaxMergeResult(false, null, $findings, false);
        }

        $costItem = new CostItemInput(
            sprintf('grundsteuer-%s', $propertyTax->unitKey),
            self::CATEGORY_KEY,
            'Grundsteuer',
            $propertyTax->annualAmount,
            $allocationKeyRef,
            AllocabilityStatus::ALLOCABLE,
            $propertyTax->period,
            null,
            TaxBenefitCategory::NONE,
            null,
            true,
            $propertyTax->fileReference === null
                ? 'Grundsteuerbescheid'
                : sprintf('Grundsteuerbescheid, Aktenzeichen %s', $propertyTax->fileReference)
        );

        $findings[] = CheckFinding::passed(
            CheckCode::PROPERTY_TAX_ADDED,
            sprintf(
                'Die Grundsteuer über %s ist in der Hausgeldabrechnung nicht enthalten und wurde als eigene '
                .'Betriebskostenposition übernommen.',
                $propertyTax->annualAmount->format()
            ),
            ['unitKey' => $propertyTax->unitKey, 'amountCent' => $propertyTax->annualAmount->cents]
        );

        return new PropertyTaxMergeResult(true, $costItem, $findings, false);
    }
}
