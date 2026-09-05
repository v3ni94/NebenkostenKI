<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Gewerbliches Mietverhaeltnis im Abrechnungslauf.
 *
 * Die Anwendung rechnet Wohnraum ab. Ein gewerbliches Mietverhaeltnis wird
 * nicht stillschweigend nach Wohnraumlogik behandelt (Masterprompt 1.2,
 * ARCHITECTURE.md Abschnitt 11). Der Befund blockiert Vorschau, Bestaetigung
 * und Zahlung und ist nicht durch eine Nutzerentscheidung aufloesbar, weil er
 * einen Sachverhalt beschreibt, den die Anwendung derzeit nicht abbildet.
 */
final class CommercialTenancyRule extends AbstractRule
{
    protected const string CODE = 'GEWERBE_MIETVERHAELTNIS';

    protected const string TITLE = 'Gewerbliches Mietverhältnis';

    protected const string DESCRIPTION = 'Prüft, ob der Abrechnungslauf ein als Gewerbe erfasstes Mietverhältnis '
        .'enthält. Gewerbe wird von dieser Anwendung nicht automatisch abgerechnet.';

    protected const string REFERENCE = 'Produktentscheidung: Die Anwendung erstellt Betriebskostenabrechnungen '
        .'für Wohnraum. Für Gewerberaum können abweichende vertragliche Vereinbarungen gelten, die hier nicht '
        .'abgebildet sind.';

    protected const string PASSED_DESCRIPTION = 'Der Abrechnungslauf enthält kein gewerbliches Mietverhältnis.';

    protected const bool USER_RESOLVABLE = false;

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::BLOCKER;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->tenancies as $tenancy) {
            if (! $tenancy->isCommercial) {
                continue;
            }

            $findings[] = RuleFinding::blocker(
                sprintf(
                    'Das Mietverhältnis "%s" in der Einheit "%s" ist als Gewerbe erfasst. Gewerbliche '
                    .'Mietverhältnisse werden von dieser Anwendung derzeit nicht abgerechnet, weil dafür andere '
                    .'Vereinbarungen gelten können als bei Wohnraum. Bitte rechnen Sie dieses Mietverhältnis '
                    .'außerhalb der Anwendung ab oder prüfen Sie, ob die Art des Mietverhältnisses richtig '
                    .'erfasst ist.',
                    $tenancy->displayName,
                    $context->unitLabel($tenancy->unitKey)
                ),
                'Tenancy',
                $tenancy->key,
                ['unitKey' => $tenancy->unitKey]
            );
        }

        return $findings;
    }
}
