<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Domain\Money\Money;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Grundsteuer doppelt vorhanden.
 *
 * Ist die Grundsteuer sowohl separat als auch in der Hausgeldabrechnung
 * enthalten, wird nicht addiert. Es entsteht eine Pruefaufgabe.
 */
final class PropertyTaxDuplicateRule extends AbstractRule
{
    protected const string CODE = 'GRUNDSTEUER_DOPPELT';

    protected const string TITLE = 'Grundsteuer mehrfach erfasst';

    protected const string DESCRIPTION = 'Prüft, ob die Grundsteuer mehrfach erfasst ist, etwa separat und '
        .'zusätzlich in der Hausgeldabrechnung.';

    protected const string REFERENCE = 'Fachliche Dublettenprüfung der Grundsteuer. Bei möglicher Dublette erfolgt '
        .'keine Addition.';

    protected const string PASSED_DESCRIPTION = 'Die Grundsteuer ist höchstens einmal erfasst.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::WARNUNG;
    }

    public function evaluate(RuleContext $context): array
    {
        $propertyTaxItems = array_values(array_filter(
            $context->costItems,
            static fn ($item): bool => $item->isPropertyTax
        ));

        if (count($propertyTaxItems) < 2) {
            return [];
        }

        $labels = [];
        $amounts = [];

        foreach ($propertyTaxItems as $item) {
            $labels[] = sprintf('"%s" (%s)', $item->description, $item->amount->format());
            $amounts[] = $item->amount;
        }

        return [
            RuleFinding::warnung(
                sprintf(
                    'Die Grundsteuer ist %d mal erfasst: %s. Die Summe von %s wird nicht automatisch angesetzt. '
                    .'Bitte prüfen Sie, ob eine der Positionen bereits in der Hausgeldabrechnung enthalten ist.',
                    count($propertyTaxItems),
                    implode(', ', $labels),
                    Money::sumOf($amounts)->format()
                ),
                'CostItem',
                $propertyTaxItems[0]->key,
                ['occurrences' => count($propertyTaxItems)]
            ),
        ];
    }
}
