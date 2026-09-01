<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Domain\Period\PeriodCoverage;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\GermanDate;
use App\Rules\Engine\RuleFinding;

/**
 * Luecke in der Abdeckung des Abrechnungszeitraums.
 *
 * Eine Luecke ist kein Fehler, sondern regelmaessig Leerstand. Die Kosten des
 * Leerstands bleiben beim Eigentuemer. Der Hinweis macht die Zurechnung
 * sichtbar.
 */
final class TenancyCoverageGapRule extends AbstractRule
{
    protected const string CODE = 'MIETZEIT_LUECKE';

    protected const string TITLE = 'Lücke in der Abdeckung';

    protected const string DESCRIPTION = 'Prüft, ob der Abrechnungszeitraum je Einheit vollständig durch '
        .'Mietzeiträume abgedeckt ist, und weist Lücken als Leerstand aus.';

    protected const string REFERENCE = 'Fachliche Prüfung der Zeitachse. Leerstandsanteile trägt der Eigentümer.';

    protected const string PASSED_DESCRIPTION = 'Der Abrechnungszeitraum ist je Einheit vollständig abgedeckt.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::HINWEIS;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->units as $unit) {
            $periods = [];

            foreach ($context->tenanciesOfUnit($unit->key) as $tenancy) {
                $periods[] = $tenancy->period;
            }

            foreach (PeriodCoverage::gapsWithin($context->billingPeriod, $periods) as $gap) {
                $findings[] = RuleFinding::hinweis(
                    sprintf(
                        'In der Einheit "%s" ist der Zeitraum %s (%d Tage) durch kein Mietverhältnis abgedeckt. '
                        .'Der Anteil wird als Leerstand dem Eigentümer zugerechnet.',
                        $unit->label,
                        GermanDate::period($gap),
                        $gap->days()
                    ),
                    'Unit',
                    $unit->key,
                    ['gapStart' => $gap->startIso(), 'gapEnd' => $gap->endIso(), 'gapDays' => $gap->days()]
                );
            }
        }

        return $findings;
    }
}
