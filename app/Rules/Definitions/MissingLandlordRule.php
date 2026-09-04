<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Fehlender Vermieter als Absender.
 *
 * Absender und inhaltlich Verantwortlicher der Betriebskostenabrechnung ist
 * der Vermieter beziehungsweise Eigentuemer (Masterprompt 2.2). Ohne
 * hinterlegten Vermieter truege die Mieterabrechnung nur die Objektbezeichnung
 * als Absenderzeile; der Mieter koennte weder Verantwortlichen noch
 * Zahlungsempfaenger erkennen. Die Finalisierung ist deshalb gesperrt, ein
 * stiller Rueckfall auf die Objektbezeichnung findet nicht statt.
 */
final class MissingLandlordRule extends AbstractRule
{
    protected const string CODE = 'VERMIETER_FEHLT';

    protected const string TITLE = 'Vermieter als Absender fehlt';

    protected const string DESCRIPTION = 'Prüft, ob für das Objekt ein Vermieter mit Name und Anschrift als Absender '
        .'der Mieterabrechnung hinterlegt ist.';

    protected const string REFERENCE = 'Fachliche Prüfung der Absenderangaben. Absender und Verantwortlicher der '
        .'Betriebskostenabrechnung ist der Vermieter, nicht die Plattform.';

    protected const string PASSED_DESCRIPTION = 'Für das Objekt ist ein Vermieter mit Name und Anschrift als Absender '
        .'hinterlegt.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::BLOCKER;
    }

    public function evaluate(RuleContext $context): array
    {
        if ($context->landlordPresent) {
            return [];
        }

        return [
            RuleFinding::blocker(
                'Für das Objekt ist kein Vermieter hinterlegt. Die Mieterabrechnung braucht Name und Anschrift des '
                .'Vermieters als Absender. Bitte erfassen Sie den Vermieter unter Objekte, Vermieter bearbeiten.',
                'Property',
                null,
                ['billingRunKey' => $context->billingRunKey]
            ),
        ];
    }
}
