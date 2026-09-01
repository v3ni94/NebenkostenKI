<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Domain\Period\DatePeriodRange;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Context\RuleCostItem;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\GermanDate;
use App\Rules\Engine\RuleFinding;

/**
 * Kosten ausserhalb des Abrechnungszeitraums.
 *
 * Ein Leistungszeitraum ohne Schnittmenge zum Abrechnungszeitraum ist eine
 * Warnung. Ein teilweise ueberlappender Leistungszeitraum ist ein Hinweis,
 * weil eine zeitliche Abgrenzung erforderlich sein kann.
 */
final class CostOutsideBillingPeriodRule extends AbstractRule
{
    protected const string CODE = 'KOSTEN_AUSSERHALB_ABRECHNUNGSZEITRAUM';

    protected const string TITLE = 'Kosten außerhalb des Abrechnungszeitraums';

    protected const string DESCRIPTION = 'Prüft, ob der Leistungszeitraum einer Kostenposition in den '
        .'Abrechnungszeitraum fällt.';

    protected const string REFERENCE = 'Fachliche Prüfung der zeitlichen Zuordnung von Betriebskosten. '
        .'Keine Rechtsberatung im Einzelfall.';

    protected const string PASSED_DESCRIPTION = 'Alle Kostenpositionen mit Leistungszeitraum liegen vollständig im '
        .'Abrechnungszeitraum.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::WARNUNG;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->costItems as $item) {
            $servicePeriod = $item->servicePeriod;

            if (! $servicePeriod instanceof DatePeriodRange) {
                continue;
            }

            if ($context->billingPeriod->containsPeriod($servicePeriod)) {
                continue;
            }

            $findings[] = $servicePeriod->overlaps($context->billingPeriod)
                ? $this->partialFinding($item, $servicePeriod, $context)
                : $this->outsideFinding($item, $servicePeriod, $context);
        }

        return $findings;
    }

    private function outsideFinding(RuleCostItem $item, DatePeriodRange $servicePeriod, RuleContext $context): RuleFinding
    {
        return RuleFinding::warnung(
            sprintf(
                'Die Position "%s" (%s) hat den Leistungszeitraum %s und liegt damit vollständig außerhalb des '
                .'Abrechnungszeitraums %s. Bitte prüfen Sie, ob die Position in diese Abrechnung gehört.',
                $item->description,
                $item->amount->format(),
                GermanDate::period($servicePeriod),
                GermanDate::period($context->billingPeriod)
            ),
            'CostItem',
            $item->key,
            ['servicePeriodStart' => $servicePeriod->startIso(), 'servicePeriodEnd' => $servicePeriod->endIso()]
        );
    }

    private function partialFinding(RuleCostItem $item, DatePeriodRange $servicePeriod, RuleContext $context): RuleFinding
    {
        return RuleFinding::hinweis(
            sprintf(
                'Die Position "%s" (%s) hat den Leistungszeitraum %s und reicht damit über den Abrechnungszeitraum '
                .'%s hinaus. Eine zeitliche Abgrenzung kann erforderlich sein.',
                $item->description,
                $item->amount->format(),
                GermanDate::period($servicePeriod),
                GermanDate::period($context->billingPeriod)
            ),
            'CostItem',
            $item->key,
            [
                'servicePeriodStart' => $servicePeriod->startIso(),
                'servicePeriodEnd' => $servicePeriod->endIso(),
                'overlappingDays' => $servicePeriod->overlappingDays($context->billingPeriod),
            ]
        );
    }
}
