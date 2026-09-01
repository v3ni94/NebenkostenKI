<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Domain\Period\DatePeriodRange;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Fehlender Leistungszeitraum.
 *
 * Ein fehlender Leistungszeitraum wird nicht geschaetzt. Die Position bleibt
 * offen und wird als Pruefaufgabe gemeldet.
 */
final class MissingServicePeriodRule extends AbstractRule
{
    protected const string CODE = 'LEISTUNGSZEITRAUM_FEHLT';

    protected const string TITLE = 'Fehlender Leistungszeitraum';

    protected const string DESCRIPTION = 'Prüft, ob zu jeder Kostenposition ein Leistungszeitraum vorliegt. Ein '
        .'fehlender Zeitraum wird nicht geschätzt.';

    protected const string REFERENCE = 'Fachliche Prüfung der zeitlichen Zuordnung von Betriebskosten.';

    protected const string PASSED_DESCRIPTION = 'Zu allen Kostenpositionen liegt ein Leistungszeitraum vor.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::WARNUNG;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->costItems as $item) {
            if ($item->servicePeriod instanceof DatePeriodRange) {
                continue;
            }

            $findings[] = RuleFinding::warnung(
                sprintf(
                    'Für die Position "%s" (%s) ist kein Leistungszeitraum erfasst. Bitte ergänzen Sie den '
                    .'Zeitraum, damit die zeitliche Zuordnung nachvollziehbar ist.',
                    $item->description,
                    $item->amount->format()
                ),
                'CostItem',
                $item->key,
                ['categoryKey' => $item->categoryKey]
            );
        }

        return $findings;
    }
}
