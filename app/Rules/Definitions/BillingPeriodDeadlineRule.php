<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\GermanDate;
use App\Rules\Engine\RuleFinding;

/**
 * Abrechnungsfrist anhand des Abrechnungszeitraums.
 *
 * Bewusst allgemein formuliert: Es wird von der "gesetzlichen
 * Abrechnungsfrist" gesprochen, ohne Paragrafenangabe. Die Monatsgrenze
 * stammt aus config('smartabrechnen.tolerances.billing_period_months_limit').
 * Die Berechnung ist eine Orientierung und ausdruecklich zu verifizieren.
 */
final class BillingPeriodDeadlineRule extends AbstractRule
{
    protected const string CODE = 'FRIST_ABRECHNUNGSZEITRAUM';

    protected const string TITLE = 'Abrechnungsfrist';

    protected const string DESCRIPTION = 'Prüft, wie viele Monate zwischen dem Ende des Abrechnungszeitraums und der '
        .'Erstellung der Abrechnung liegen, und weist auf die gesetzliche Abrechnungsfrist hin.';

    protected const string REFERENCE = 'Allgemeine Information zur gesetzlichen Abrechnungsfrist für '
        .'Betriebskostenabrechnungen im Wohnraummietrecht. Keine Rechtsberatung im Einzelfall.';

    protected const string PASSED_DESCRIPTION = 'Die Abrechnung wird innerhalb der konfigurierten Monatsgrenze nach '
        .'Ende des Abrechnungszeitraums erstellt.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::HINWEIS;
    }

    public function evaluate(RuleContext $context): array
    {
        $limit = $context->tolerances->billingPeriodMonthsLimit;
        $months = $this->fullMonthsBetween($context);

        if ($months <= $limit) {
            return [];
        }

        return [
            RuleFinding::hinweis(
                sprintf(
                    'Zwischen dem Ende des Abrechnungszeitraums am %s und der Erstellung am %s liegen %d Monate. '
                    .'Die Grenze von %d Monaten ist überschritten. Allgemeine Information: Nach Ablauf der '
                    .'gesetzlichen Abrechnungsfrist sind Nachforderungen regelmäßig ausgeschlossen, ein Guthaben '
                    .'ist gleichwohl an den Mieter auszukehren. Diese Fristberechnung ist eine Orientierung und '
                    .'rechtlich zu prüfen. Bitte klären Sie die Frist vor dem Versand.',
                    GermanDate::day($context->billingPeriod->end),
                    GermanDate::day($context->preparedOn),
                    $months,
                    $limit
                ),
                'BillingRun',
                null,
                [
                    'monthsElapsed' => $months,
                    'monthsLimit' => $limit,
                    'periodEnd' => $context->billingPeriod->endIso(),
                ]
            ),
        ];
    }

    /**
     * Vollstaendige Monate zwischen Periodenende und Erstellungsdatum.
     */
    private function fullMonthsBetween(RuleContext $context): int
    {
        if ($context->preparedOn <= $context->billingPeriod->end) {
            return 0;
        }

        $diff = $context->billingPeriod->end->diff($context->preparedOn);

        return (int) $diff->y * 12 + (int) $diff->m;
    }
}
