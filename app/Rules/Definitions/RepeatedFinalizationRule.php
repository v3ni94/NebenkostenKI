<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Mehrfachfinalisierung.
 *
 * Ein finalisiertes PDF wird niemals ueberschrieben. Eine Korrektur erzeugt
 * eine neue Version und setzt eine ausdrueckliche Freigabe voraus
 * (Pflichtenheft Abschnitt 11.5).
 */
final class RepeatedFinalizationRule extends AbstractRule
{
    protected const string CODE = 'MEHRFACHFINALISIERUNG';

    protected const string TITLE = 'Erneute Finalisierung';

    protected const string DESCRIPTION = 'Prüft, ob der Abrechnungslauf bereits finalisiert wurde und ob eine '
        .'Korrektur ausdrücklich freigegeben ist.';

    protected const string REFERENCE = 'Fachliche Vorgabe: Ein finalisiertes Dokument wird nicht überschrieben. Eine '
        .'Korrektur erzeugt eine neue Version, die alte Version bleibt erhalten.';

    protected const string PASSED_DESCRIPTION = 'Es liegt keine unzulässige erneute Finalisierung vor.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::BLOCKER;
    }

    public function evaluate(RuleContext $context): array
    {
        $state = $context->finalizationState;

        if ($state->finalizedVersionCount < 1) {
            return [];
        }

        if ($state->correctionApproved) {
            return [
                RuleFinding::hinweis(
                    sprintf(
                        'Der Abrechnungslauf ist bereits %d mal finalisiert. Die Korrektur ist freigegeben und '
                        .'erzeugt eine neue Version. Die bisherigen Dokumente bleiben als ersetzte Version '
                        .'erhalten.',
                        $state->finalizedVersionCount
                    ),
                    'BillingRun',
                    null,
                    ['finalizedVersionCount' => $state->finalizedVersionCount]
                ),
            ];
        }

        return [
            RuleFinding::blocker(
                sprintf(
                    'Der Abrechnungslauf ist bereits %d mal finalisiert. Eine erneute Finalisierung ist nur als '
                    .'ausdrücklich freigegebene Korrektur möglich. Bitte holen Sie die Freigabe ein.',
                    $state->finalizedVersionCount
                ),
                'BillingRun',
                null,
                ['finalizedVersionCount' => $state->finalizedVersionCount]
            ),
        ];
    }
}
