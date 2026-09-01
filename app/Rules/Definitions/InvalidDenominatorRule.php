<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;
use Brick\Math\BigDecimal;

/**
 * Nenner null oder negative Werte.
 *
 * Ein Nenner von null fuehrt zu einer Division durch null, negative
 * Zaehlerwerte zu einer sinnlosen Verteilung. Beides blockiert.
 */
final class InvalidDenominatorRule extends AbstractRule
{
    protected const string CODE = 'NENNER_UNGUELTIG';

    protected const string TITLE = 'Ungültiger Nenner oder negativer Schlüsselwert';

    protected const string DESCRIPTION = 'Prüft, ob der Gesamtnenner eines Verteilerschlüssels größer als null ist '
        .'und ob alle Zählerwerte nicht negativ sind.';

    protected const string REFERENCE = 'Technische Prüfung der Verteilungsrechnung.';

    protected const string PASSED_DESCRIPTION = 'Alle Verteilerschlüssel haben einen positiven Nenner und keine '
        .'negativen Zählerwerte.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::BLOCKER;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->allocationKeys as $key) {
            $denominator = $key->denominatorValue();

            if (! $denominator instanceof BigDecimal) {
                $findings[] = RuleFinding::blocker(
                    sprintf(
                        'Für den Verteilerschlüssel "%s" ist kein Gesamtnenner erfasst. Eine Verteilung ist damit '
                        .'nicht möglich.',
                        $key->label
                    ),
                    'AllocationKey',
                    $key->key,
                    ['keyType' => $key->keyType]
                );
            } elseif ($denominator->isNegativeOrZero()) {
                $findings[] = RuleFinding::blocker(
                    sprintf(
                        'Der Verteilerschlüssel "%s" hat den Gesamtnenner %s. Der Nenner muss größer als null sein.',
                        $key->label,
                        $denominator->__toString()
                    ),
                    'AllocationKey',
                    $key->key,
                    ['keyType' => $key->keyType, 'denominator' => $denominator->__toString()]
                );
            }

            $negativeUnits = $key->unitsWithNegativeValue();

            if ($negativeUnits === []) {
                continue;
            }

            $labels = array_map(
                static fn (string $unitKey): string => $context->unitLabel($unitKey),
                $negativeUnits
            );

            $findings[] = RuleFinding::blocker(
                sprintf(
                    'Im Verteilerschlüssel "%s" sind negative Zählerwerte erfasst: %s. Bitte korrigieren Sie die '
                    .'Werte.',
                    $key->label,
                    implode(', ', $labels)
                ),
                'AllocationKey',
                $key->key,
                ['negativeUnits' => implode(', ', $negativeUnits)]
            );
        }

        return $findings;
    }
}
