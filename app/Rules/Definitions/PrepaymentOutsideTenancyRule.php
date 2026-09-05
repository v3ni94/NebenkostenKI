<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Context\RuleTenancy;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\GermanDate;
use App\Rules\Engine\RuleFinding;

/**
 * Vorauszahlungen ausserhalb des Mietzeitraums.
 */
final class PrepaymentOutsideTenancyRule extends AbstractRule
{
    protected const string CODE = 'VORAUSZAHLUNG_AUSSERHALB_MIETZEIT';

    protected const string TITLE = 'Vorauszahlung außerhalb des Mietzeitraums';

    protected const string DESCRIPTION = 'Prüft, ob der Zeitraum einer Vorauszahlung innerhalb des Mietzeitraums '
        .'des zugehörigen Mietverhältnisses liegt.';

    protected const string REFERENCE = 'Fachliche Prüfung der zeitlichen Zuordnung von Vorauszahlungen.';

    protected const string PASSED_DESCRIPTION = 'Alle Vorauszahlungen liegen innerhalb des jeweiligen '
        .'Mietzeitraums.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::WARNUNG;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->prepayments as $prepayment) {
            $tenancy = $context->tenancy($prepayment->tenancyKey);

            if (! $tenancy instanceof RuleTenancy) {
                $findings[] = RuleFinding::warnung(
                    sprintf(
                        'Die Vorauszahlung für den Zeitraum %s ist keinem Mietverhältnis dieses Abrechnungslaufs '
                        .'zugeordnet.',
                        GermanDate::period($prepayment->period)
                    ),
                    'Prepayment',
                    $prepayment->key,
                    ['tenancyKey' => $prepayment->tenancyKey]
                );

                continue;
            }

            if ($tenancy->period->containsPeriod($prepayment->period)) {
                continue;
            }

            $findings[] = RuleFinding::warnung(
                sprintf(
                    'Die Vorauszahlung für %s liegt außerhalb des Mietzeitraums %s von %s. Bitte prüfen Sie den '
                    .'Zeitraum und den Betrag.',
                    GermanDate::period($prepayment->period),
                    GermanDate::period($tenancy->period),
                    $tenancy->displayName
                ),
                'Prepayment',
                $prepayment->key,
                [
                    'tenancyKey' => $tenancy->key,
                    'prepaymentStart' => $prepayment->period->startIso(),
                    'prepaymentEnd' => $prepayment->period->endIso(),
                ]
            );
        }

        return $findings;
    }
}
