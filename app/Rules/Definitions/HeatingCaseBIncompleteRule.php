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
 * Heizkostenfall B ohne manuell erfasste Betraege.
 *
 * Eine Eigenberechnung nach Heizkostenverordnung ist bewusst nicht Teil des
 * Leistungsumfangs. Fall B wird ueber die manuelle Erfassung abgedeckt: Der
 * Vermieter ermittelt die Betraege je Einheit selbst und traegt sie ein. Die
 * Regel greift, wenn Fall B gewaehlt ist und fuer mindestens eine Einheit
 * keine erfassten Betraege vorliegen. Fehlende Betraege werden niemals
 * geschaetzt (Grundsatz 5).
 *
 * Der Hinweis ist ausdruecklich nicht wegklickbar (USER_RESOLVABLE = false).
 * Er nennt die Heizkostenverordnung nur als allgemeine Information; zur Hoehe
 * eines Kuerzungsrechts wird bewusst keine Zahl genannt.
 */
final class HeatingCaseBIncompleteRule extends AbstractRule
{
    protected const string CODE = 'HEIZKOSTEN_FALL_B_UNVOLLSTAENDIG';

    protected const string TITLE = 'Heizkosten ohne erfasste Beträge je Einheit';

    protected const string DESCRIPTION = 'Prüft bei Zentralheizung ohne externen Abrechner, ob für jede Einheit '
        .'Beträge erfasst sind. Die Plattform rechnet die Verteilung nicht selbst.';

    protected const string REFERENCE = 'Allgemeine Information zur Heizkostenverordnung: Sie verlangt grundsätzlich '
        .'eine überwiegend verbrauchsabhängige Abrechnung. Der Einzelfall ist rechtlich zu prüfen.';

    protected const string PASSED_DESCRIPTION = 'Für alle Einheiten sind Heizkosten erfasst oder es besteht kein '
        .'Fall einer manuellen Erfassung.';

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

            $missing = $this->unitsWithoutAmounts($context, $statement->unitKeysWithAmounts);

            if ($missing === []) {
                continue;
            }

            $findings[] = RuleFinding::blocker(
                sprintf(
                    'Für die Heizkosten im Zeitraum %s liegen für folgende Einheiten keine erfassten Beträge vor: '
                    .'%s. Bei Zentralheizung ohne externen Abrechner tragen Sie die von Ihnen ermittelten Beträge '
                    .'je Einheit selbst ein; die Plattform übernimmt sie unverändert und berechnet die Verteilung '
                    .'nicht selbst. Allgemeine Information: Die Heizkostenverordnung verlangt grundsätzlich eine '
                    .'überwiegend verbrauchsabhängige Abrechnung. Bei einer rein flächenbasierten Verteilung kann '
                    .'Mietern ein pauschales Kürzungsrecht zustehen. Wir empfehlen, einen Messdienstleister zu '
                    .'beauftragen. Dieser Hinweis ist nicht wegklickbar und rechtlich zu prüfen.',
                    GermanDate::period($statement->period),
                    implode(', ', $missing)
                ),
                'HeatingStatement',
                $statement->key,
                [
                    'einheitenOhneBetraege' => implode(', ', $missing),
                    'supplyCase' => $statement->supplyCase->value,
                    'manuellErfasst' => $statement->manualEntry,
                ]
            );
        }

        return $findings;
    }

    /**
     * Einheiten des Objekts ohne erfasste Betraege.
     *
     * @param  list<string>  $unitKeysWithAmounts
     * @return list<string>
     */
    private function unitsWithoutAmounts(RuleContext $context, array $unitKeysWithAmounts): array
    {
        if ($context->units === []) {
            return $unitKeysWithAmounts === [] ? ['alle Einheiten'] : [];
        }

        $missing = [];

        foreach ($context->units as $unit) {
            if (in_array($unit->key, $unitKeysWithAmounts, true)) {
                continue;
            }

            $missing[] = $unit->label;
        }

        return $missing;
    }
}
