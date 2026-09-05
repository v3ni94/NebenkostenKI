<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Rechnung und zugehoerige Gutschrift.
 *
 * Eine Gutschrift mit erkannter Rechnung ist ein Hinweis auf die
 * durchgefuehrte Verrechnung. Eine Gutschrift ohne zugeordnete Rechnung ist
 * eine Warnung, weil die Zuordnung offen bleibt.
 */
final class CreditNoteRule extends AbstractRule
{
    protected const string CODE = 'RECHNUNG_MIT_GUTSCHRIFT';

    protected const string TITLE = 'Rechnung und Gutschrift';

    protected const string DESCRIPTION = 'Prüft, ob eine Gutschrift einer Rechnung zugeordnet ist und ob die '
        .'Verrechnung nachvollziehbar bleibt.';

    protected const string REFERENCE = 'Fachliche Prüfung der Verrechnung von Gutschriften. Eine Gutschrift ist ein '
        .'eigener Vorgang mit umgekehrtem Vorzeichen.';

    protected const string PASSED_DESCRIPTION = 'Es liegen keine offenen Gutschriften ohne zugeordnete Rechnung vor.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::HINWEIS;
    }

    public function evaluate(RuleContext $context): array
    {
        $keys = [];

        foreach ($context->costItems as $item) {
            $keys[] = $item->key;
        }

        $findings = [];

        foreach ($context->costItems as $item) {
            if (! $item->isCreditNote) {
                continue;
            }

            if ($item->relatedInvoiceKey !== null && in_array($item->relatedInvoiceKey, $keys, true)) {
                $findings[] = RuleFinding::hinweis(
                    sprintf(
                        'Die Gutschrift "%s" (%s) ist einer Rechnung zugeordnet und wird mit umgekehrtem Vorzeichen '
                        .'in derselben Kostenart verrechnet.',
                        $item->description,
                        $item->amount->format()
                    ),
                    'CostItem',
                    $item->key,
                    ['relatedInvoiceKey' => $item->relatedInvoiceKey]
                );

                continue;
            }

            $findings[] = RuleFinding::warnung(
                sprintf(
                    'Die Gutschrift "%s" (%s) ist keiner Rechnung dieses Abrechnungslaufs zugeordnet. Bitte '
                    .'prüfen Sie, auf welche Kostenposition sich die Gutschrift bezieht.',
                    $item->description,
                    $item->amount->format()
                ),
                'CostItem',
                $item->key,
                ['relatedInvoiceKey' => $item->relatedInvoiceKey]
            );
        }

        return $findings;
    }
}
