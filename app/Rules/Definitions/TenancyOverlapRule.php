<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Domain\Period\PeriodCoverage;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Context\RuleTenancy;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\GermanDate;
use App\Rules\Engine\RuleFinding;

/**
 * Mietzeitueberschneidung innerhalb einer Einheit.
 *
 * Die Ueberschneidungspruefung nutzt App\Domain\Period\PeriodCoverage. Eine
 * Ueberschneidung ist ein struktureller Eingabefehler und blockiert.
 */
final class TenancyOverlapRule extends AbstractRule
{
    protected const string CODE = 'MIETZEIT_UEBERSCHNEIDUNG';

    protected const string TITLE = 'Überschneidung von Mietzeiträumen';

    protected const string DESCRIPTION = 'Prüft, ob sich die Mietzeiträume einer Einheit überschneiden.';

    protected const string REFERENCE = 'Technische Prüfung der Zeitachse. Ein Mieterwechsel ist überschneidungsfrei '
        .'abzubilden.';

    protected const string PASSED_DESCRIPTION = 'Die Mietzeiträume je Einheit überschneiden sich nicht.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::BLOCKER;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->units as $unit) {
            $periods = [];

            foreach ($context->tenanciesOfUnit($unit->key) as $tenancy) {
                $periods[$tenancy->key] = $tenancy->period;
            }

            foreach (PeriodCoverage::overlappingPairs($periods) as [$leftKey, $rightKey, $intersection]) {
                $left = $context->tenancy($leftKey);
                $right = $context->tenancy($rightKey);

                $findings[] = RuleFinding::blocker(
                    sprintf(
                        'In der Einheit "%s" überschneiden sich die Mietzeiträume von %s und %s im Zeitraum %s. '
                        .'Bitte korrigieren Sie die Zeitachse.',
                        $unit->label,
                        $left instanceof RuleTenancy ? $left->displayName : $leftKey,
                        $right instanceof RuleTenancy ? $right->displayName : $rightKey,
                        GermanDate::period($intersection)
                    ),
                    'Unit',
                    $unit->key,
                    [
                        'tenancyKeyLeft' => $leftKey,
                        'tenancyKeyRight' => $rightKey,
                        'overlapDays' => $intersection->days(),
                    ]
                );
            }
        }

        return $findings;
    }
}
