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
 * Im Vorjahr vorhandene, diesmal fehlende Kostenart (Vergessens-Check).
 */
final class MissingPreviousYearCategoryRule extends AbstractRule
{
    protected const string CODE = 'VORJAHR_KATEGORIE_FEHLT';

    protected const string TITLE = 'Kostenart aus dem Vorjahr fehlt';

    protected const string DESCRIPTION = 'Prüft, ob eine im Vorjahr abgerechnete Kostenart in diesem '
        .'Abrechnungslauf fehlt.';

    protected const string REFERENCE = 'Fachlicher Vollständigkeitsvergleich gegen den Vorjahreslauf.';

    protected const string PASSED_DESCRIPTION = 'Alle im Vorjahr abgerechneten Kostenarten sind auch in diesem '
        .'Abrechnungslauf vorhanden.';

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
            if ($finding->code !== CheckCode::PREVIOUS_YEAR_CATEGORY_MISSING) {
                continue;
            }

            $categoryKey = $finding->context['categoryKey'] ?? null;

            $findings[] = RuleFinding::warnung(
                $finding->message.' Bitte prüfen Sie, ob ein Beleg fehlt oder die Kostenart bewusst entfällt.',
                'CostCategory',
                is_string($categoryKey) ? $categoryKey : null,
                ['categoryKey' => is_string($categoryKey) ? $categoryKey : null]
            );
        }

        return $findings;
    }
}
