<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Hausgeld-Einzelanteile gegen Gesamtsumme.
 *
 * Die Einzelanteile der Einheiten muessen die ausgewiesene Gesamtsumme der
 * Kostenart ergeben. Eine Abweichung oberhalb der Toleranz blockiert.
 */
final class HausgeldShareChecksumRule extends AbstractRule
{
    protected const string CODE = 'HAUSGELD_ANTEILE_GEGEN_GESAMTSUMME';

    protected const string TITLE = 'Hausgeldanteile gegen Gesamtsumme';

    protected const string DESCRIPTION = 'Vergleicht die Summe der Einzelanteile einer Position der '
        .'Hausgeldabrechnung mit der dort ausgewiesenen Gesamtsumme.';

    protected const string REFERENCE = 'Technische Prüfsumme der Hausgeldabrechnung. Die Kennzeichnung einer '
        .'WEG-Position als umlagefähig ist ein Vorschlag und keine Freigabe.';

    protected const string PASSED_DESCRIPTION = 'Die Einzelanteile der Hausgeldabrechnung ergeben die ausgewiesene '
        .'Gesamtsumme im Rahmen der Toleranz.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::BLOCKER;
    }

    public function evaluate(RuleContext $context): array
    {
        $tolerance = $context->tolerances->checksum();
        $findings = [];

        foreach ($context->hausgeldChecksums as $checksum) {
            $difference = $checksum->difference();

            if ($difference->absolute()->compareTo($tolerance) <= 0) {
                continue;
            }

            $findings[] = RuleFinding::blocker(
                sprintf(
                    'In der Hausgeldposition "%s" ergeben die Einzelanteile %s, ausgewiesen sind %s. Die '
                    .'Abweichung beträgt %s und überschreitet die Toleranz von %s. Bitte klären Sie die '
                    .'Abweichung vor der Finalisierung.',
                    $checksum->positionLabel,
                    $checksum->sumOfShares()->format(),
                    $checksum->declaredTotal->format(),
                    $difference->format(),
                    $tolerance->format()
                ),
                'HausgeldPosition',
                null,
                [
                    'positionLabel' => $checksum->positionLabel,
                    'differenceCent' => $difference->cents,
                    'toleranceCent' => $tolerance->cents,
                ]
            );
        }

        return $findings;
    }
}
