<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\HeatingSupplyCase;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\GermanDate;
use App\Rules\Engine\RuleFinding;

/**
 * Heizkostenfall B ohne vollstaendige Daten.
 *
 * Der Hinweis ist ausdruecklich nicht wegklickbar (USER_RESOLVABLE = false).
 * Er nennt die Heizkostenverordnung, weil deren Grundsatz der ueberwiegend
 * verbrauchsabhaengigen Abrechnung gesichert ist. Zur Hoehe eines
 * Kuerzungsrechts wird bewusst keine Zahl genannt.
 */
final class HeatingCaseBIncompleteRule extends AbstractRule
{
    protected const string CODE = 'HEIZKOSTEN_FALL_B_UNVOLLSTAENDIG';

    protected const string TITLE = 'Heizkosten ohne vollständige Verbrauchsdaten';

    protected const string DESCRIPTION = 'Prüft bei Zentralheizung ohne externe Abrechnung, ob alle für eine '
        .'verbrauchsabhängige Eigenberechnung erforderlichen Angaben vorliegen.';

    protected const string REFERENCE = 'Allgemeine Information zur Heizkostenverordnung: Sie verlangt grundsätzlich '
        .'eine überwiegend verbrauchsabhängige Abrechnung. Der Einzelfall ist rechtlich zu prüfen.';

    protected const string PASSED_DESCRIPTION = 'Für die Heizkostenabrechnung liegen die erforderlichen Angaben '
        .'vollständig vor oder es besteht kein Fall einer Eigenberechnung.';

    protected const bool USER_RESOLVABLE = false;

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::BLOCKER;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->heatingStatements as $statement) {
            if ($statement->supplyCase !== HeatingSupplyCase::ZENTRAL_OHNE_EXTERN) {
                continue;
            }

            $missing = $statement->missingFieldsForOwnCalculation();

            if ($missing === []) {
                continue;
            }

            $findings[] = RuleFinding::blocker(
                sprintf(
                    'Für die Heizkosten im Zeitraum %s fehlen folgende Angaben: %s. Eine verbrauchsabhängige '
                    .'Eigenberechnung ist damit nicht möglich. Allgemeine Information: Die '
                    .'Heizkostenverordnung verlangt grundsätzlich eine überwiegend verbrauchsabhängige '
                    .'Abrechnung. Bei einer rein flächenbasierten Verteilung kann Mietern ein pauschales '
                    .'Kürzungsrecht zustehen. Wir empfehlen, einen Messdienstleister zu beauftragen und die '
                    .'Verbrauchswerte nachzureichen. Dieser Hinweis ist nicht wegklickbar und rechtlich zu '
                    .'prüfen.',
                    GermanDate::period($statement->period),
                    implode(', ', $missing)
                ),
                'HeatingStatement',
                $statement->key,
                [
                    'missing' => implode(', ', $missing),
                    'supplyCase' => $statement->supplyCase->value,
                ]
            );
        }

        return $findings;
    }
}
