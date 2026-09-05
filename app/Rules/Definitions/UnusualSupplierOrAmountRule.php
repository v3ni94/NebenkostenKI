<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Domain\Money\Money;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Neuer Lieferant oder ungewoehnlich hoher Einzelbetrag.
 *
 * Beides ist ein Aufmerksamkeitshinweis, keine fachliche Bewertung. Ein neuer
 * Lieferant wird nur gemeldet, wenn ein Vorjahreslauf als Vergleichsbasis
 * vorliegt.
 */
final class UnusualSupplierOrAmountRule extends AbstractRule
{
    protected const string CODE = 'NEUER_LIEFERANT_ODER_HOHER_EINZELBETRAG';

    protected const string TITLE = 'Neuer Lieferant oder hoher Einzelbetrag';

    protected const string DESCRIPTION = 'Weist auf Lieferanten hin, die im Vorjahr nicht vorkamen, und auf '
        .'Einzelbeträge oberhalb der Aufmerksamkeitsschwelle.';

    protected const string REFERENCE = 'Fachlicher Aufmerksamkeitshinweis ohne Bewertung der Umlagefähigkeit.';

    protected const string PASSED_DESCRIPTION = 'Es liegen keine neuen Lieferanten und keine auffällig hohen '
        .'Einzelbeträge vor.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::HINWEIS;
    }

    public function evaluate(RuleContext $context): array
    {
        $history = $context->supplierHistory;
        $threshold = $history->singleAmountAttentionThreshold;
        $findings = [];
        $reportedSuppliers = [];

        foreach ($context->costItems as $item) {
            $supplier = $item->supplier;

            if (
                $history->previousRunAvailable
                && $supplier !== null
                && trim($supplier) !== ''
                && ! $history->isKnown($supplier)
                && ! in_array($supplier, $reportedSuppliers, true)
            ) {
                $reportedSuppliers[] = $supplier;

                $findings[] = RuleFinding::hinweis(
                    sprintf(
                        'Der Lieferant "%s" kam im Vorjahr nicht vor. Bitte prüfen Sie die Position "%s" (%s).',
                        $supplier,
                        $item->description,
                        $item->amount->format()
                    ),
                    'CostItem',
                    $item->key,
                    ['supplier' => $supplier]
                );
            }

            if ($threshold instanceof Money && $item->amount->absolute()->isGreaterThan($threshold)) {
                $findings[] = RuleFinding::hinweis(
                    sprintf(
                        'Die Position "%s" liegt mit %s über der Aufmerksamkeitsschwelle von %s. Bitte prüfen Sie '
                        .'den Betrag und den zugehörigen Beleg.',
                        $item->description,
                        $item->amount->format(),
                        $threshold->format()
                    ),
                    'CostItem',
                    $item->key,
                    ['amountCent' => $item->amount->cents, 'thresholdCent' => $threshold->cents]
                );
            }
        }

        return $findings;
    }
}
