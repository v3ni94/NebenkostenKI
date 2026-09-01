<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\Co2ShareStatus;
use App\Enums\HeatingSupplyCase;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\GermanDate;
use App\Rules\Engine\RuleFinding;

/**
 * Status der CO2-Kostenaufteilung.
 *
 * Diese Regel gilt erst fuer Abrechnungszeitraeume ab dem 01.01.2023. Fuer
 * frueher beginnende Zeitraeume ist sie nicht Teil des Regelstands; ein
 * aelterer Berechnungsstand bleibt dadurch reproduzierbar.
 */
final class HeatingCo2ShareStatusRule extends AbstractRule
{
    protected const string CODE = 'HEIZKOSTEN_CO2_STATUS';

    protected const string VALID_FROM = '2023-01-01';

    protected const string TITLE = 'Status der CO2-Kostenaufteilung';

    protected const string DESCRIPTION = 'Prüft, ob erkennbar ist, ob die Aufteilung der CO2-Kosten in der '
        .'Heizkostenabrechnung bereits enthalten ist.';

    protected const string REFERENCE = 'Allgemeine Information: Für Abrechnungszeiträume ab dem 01.01.2023 ist die '
        .'Aufteilung der CO2-Kosten zwischen Vermieter und Mieter gesetzlich geregelt. Der Einzelfall ist '
        .'rechtlich zu prüfen.';

    protected const string PASSED_DESCRIPTION = 'Der Status der CO2-Kostenaufteilung ist in allen '
        .'Heizkostenabrechnungen erkennbar.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::WARNUNG;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->heatingStatements as $statement) {
            if ($statement->supplyCase === HeatingSupplyCase::DEZENTRAL) {
                continue;
            }

            if ($statement->co2ShareStatus !== Co2ShareStatus::UNBEKANNT) {
                continue;
            }

            $findings[] = RuleFinding::warnung(
                sprintf(
                    'Für die Heizkostenabrechnung im Zeitraum %s ist nicht erkennbar, ob die Aufteilung der '
                    .'CO2-Kosten bereits enthalten ist. Bitte klären Sie den Status mit dem Abrechnungsdienst.',
                    GermanDate::period($statement->period)
                ),
                'HeatingStatement',
                $statement->key,
                ['provider' => $statement->provider]
            );
        }

        return $findings;
    }
}
