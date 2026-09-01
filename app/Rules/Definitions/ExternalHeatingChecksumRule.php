<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Domain\Calculation\Heating\Co2AllocationStatus;
use App\Domain\Calculation\Heating\ExternalHeatingReconciler;
use App\Domain\Calculation\Heating\ExternalHeatingStatementInput;
use App\Domain\Money\Money;
use App\Enums\HeatingSupplyCase;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Externe Heizkosten-Einzelsummen gegen Gesamtbetrag (Fall A).
 *
 * Die Pruefsumme wird nicht neu programmiert, sondern von
 * App\Domain\Calculation\Heating\ExternalHeatingReconciler gebildet. Der
 * CO2-Status wird von einer eigenen Regel geprueft.
 */
final class ExternalHeatingChecksumRule extends AbstractRule
{
    protected const string CODE = 'HEIZKOSTEN_EINZELSUMMEN_GEGEN_GESAMTBETRAG';

    protected const string TITLE = 'Heizkosten Einzelsummen gegen Gesamtbetrag';

    protected const string DESCRIPTION = 'Vergleicht die Einzelbeträge einer externen Heizkostenabrechnung mit dem '
        .'dort ausgewiesenen Gesamtbetrag.';

    protected const string REFERENCE = 'Technische Prüfsumme der externen Heizkostenabrechnung. Eine Abweichung '
        .'oberhalb der Toleranz blockiert die automatische Finalisierung.';

    protected const string PASSED_DESCRIPTION = 'Die Einzelbeträge der externen Heizkostenabrechnungen stimmen mit '
        .'dem jeweiligen Gesamtbetrag im Rahmen der Toleranz überein.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::BLOCKER;
    }

    public function evaluate(RuleContext $context): array
    {
        $reconciler = new ExternalHeatingReconciler;
        $tolerance = $context->tolerances->checksum();
        $findings = [];

        foreach ($context->heatingStatements as $statement) {
            if ($statement->supplyCase !== HeatingSupplyCase::EXTERN_ABGERECHNET) {
                continue;
            }

            if (! $statement->totalAmount instanceof Money || $statement->lineAmounts === []) {
                $findings[] = RuleFinding::blocker(
                    sprintf(
                        'Zur externen Heizkostenabrechnung für den Zeitraum %s fehlen der Gesamtbetrag oder die '
                        .'Einzelbeträge. Ohne diese Angaben ist keine Prüfsumme möglich.',
                        $statement->period->startIso()
                    ),
                    'HeatingStatement',
                    $statement->key,
                    ['provider' => $statement->provider]
                );

                continue;
            }

            $result = $reconciler->reconcile(
                new ExternalHeatingStatementInput(
                    $statement->provider ?? 'unbekannt',
                    $statement->period,
                    $statement->totalAmount,
                    $statement->lineAmounts,
                    Co2AllocationStatus::UNKNOWN,
                ),
                $tolerance
            );

            if ($result->withinTolerance) {
                continue;
            }

            $findings[] = RuleFinding::blocker(
                sprintf(
                    'Die Einzelbeträge der Heizkostenabrechnung ergeben %s, ausgewiesen ist ein Gesamtbetrag von '
                    .'%s. Die Abweichung beträgt %s und überschreitet die Toleranz von %s. Eine Finalisierung ist '
                    .'erst nach Klärung möglich.',
                    $result->sumOfParticipantAmounts->format(),
                    $result->totalAmount->format(),
                    $result->difference->format(),
                    $tolerance->format()
                ),
                'HeatingStatement',
                $statement->key,
                [
                    'provider' => $statement->provider,
                    'differenceCent' => $result->difference->cents,
                    'toleranceCent' => $tolerance->cents,
                ]
            );
        }

        return $findings;
    }
}
