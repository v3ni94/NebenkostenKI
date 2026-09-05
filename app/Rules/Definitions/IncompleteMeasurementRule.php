<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Unvollstaendige Flaechen- oder Schluesselwerte.
 *
 * Fehlende Bezugsgroessen werden nicht geschaetzt. Ohne vollstaendige Werte
 * ist keine nachvollziehbare Verteilung moeglich; die Finalisierung ist
 * gesperrt.
 */
final class IncompleteMeasurementRule extends AbstractRule
{
    protected const string CODE = 'SCHLUESSELWERTE_UNVOLLSTAENDIG';

    protected const string TITLE = 'Unvollständige Flächen oder Schlüsselwerte';

    protected const string DESCRIPTION = 'Prüft, ob für jede Einheit die benötigten Bezugsgrößen und für jeden '
        .'Verteilerschlüssel alle Zählerwerte vorliegen.';

    protected const string REFERENCE = 'Fachliche Vollständigkeitsprüfung der Verteilungsgrundlagen. Fehlende Werte '
        .'werden nicht geschätzt.';

    protected const string PASSED_DESCRIPTION = 'Alle benötigten Flächen und Schlüsselwerte liegen vollständig vor.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::BLOCKER;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->units as $unit) {
            $missing = $unit->missingMeasurements();

            if ($missing === []) {
                continue;
            }

            $findings[] = RuleFinding::blocker(
                sprintf(
                    'Für die Einheit "%s" fehlen folgende Werte: %s. Bitte ergänzen Sie die Angaben, damit die '
                    .'Verteilung nachvollziehbar bleibt.',
                    $unit->label,
                    implode(', ', $missing)
                ),
                'Unit',
                $unit->key,
                ['missing' => implode(', ', $missing)]
            );
        }

        foreach ($context->allocationKeys as $key) {
            $missingUnits = $key->unitsWithoutValue();

            if ($missingUnits === []) {
                continue;
            }

            $labels = array_map(
                static fn (string $unitKey): string => $context->unitLabel($unitKey),
                $missingUnits
            );

            $findings[] = RuleFinding::blocker(
                sprintf(
                    'Im Verteilerschlüssel "%s" fehlen die Zählerwerte folgender Einheiten: %s.',
                    $key->label,
                    implode(', ', $labels)
                ),
                'AllocationKey',
                $key->key,
                ['keyType' => $key->keyType, 'missingUnits' => implode(', ', $missingUnits)]
            );
        }

        return $findings;
    }
}
