<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Domain\Calculation\Check\PreviousYearComparator;
use App\Domain\Calculation\Result\CheckCode;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\DomainInputMapper;
use App\Rules\Engine\RuleFinding;

/**
 * Kostenabweichung gegenueber dem Vorjahr.
 *
 * Die Standardwarnung greift ab der konfigurierten Prozentgrenze aus
 * config('smartabrechnen.tolerances.prior_year_deviation_percent'). Der
 * Vergleich liegt in App\Domain\Calculation\Check\PreviousYearComparator.
 */
final class PreviousYearDeviationRule extends AbstractRule
{
    protected const string CODE = 'VORJAHR_KOSTENABWEICHUNG';

    protected const string TITLE = 'Abweichung gegenüber dem Vorjahr';

    protected const string DESCRIPTION = 'Vergleicht die Kosten je Kostenart mit dem Vorjahr und warnt ab der '
        .'konfigurierten Prozentgrenze.';

    protected const string REFERENCE = 'Fachlicher Plausibilitätsvergleich. Eine Abweichung ist keine Aussage über '
        .'die Umlagefähigkeit.';

    protected const string PASSED_DESCRIPTION = 'Keine Kostenart weicht stärker als die konfigurierte Prozentgrenze '
        .'vom Vorjahr ab.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::WARNUNG;
    }

    public function evaluate(RuleContext $context): array
    {
        if ($context->previousYearCategories === []) {
            return [];
        }

        $comparator = new PreviousYearComparator;
        $findings = [];

        $results = $comparator->compare(
            DomainInputMapper::toCostItemInputs($context->costItems),
            $context->previousYearCategories,
            $context->tolerances->priorYearDeviationPercent
        );

        foreach ($results as $finding) {
            if ($finding->code !== CheckCode::PREVIOUS_YEAR_DEVIATION) {
                continue;
            }

            $categoryKey = $finding->context['categoryKey'] ?? null;

            $findings[] = RuleFinding::warnung(
                $finding->message.' Bitte bestätigen Sie die Abweichung oder korrigieren Sie den Betrag.',
                'CostCategory',
                is_string($categoryKey) ? $categoryKey : null,
                [
                    'deviationCent' => is_int($finding->context['deviationCent'] ?? null)
                        ? (int) $finding->context['deviationCent']
                        : null,
                    'thresholdPercent' => $context->tolerances->priorYearDeviationPercent,
                ]
            );
        }

        return $findings;
    }
}
