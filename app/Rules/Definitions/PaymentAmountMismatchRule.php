<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Domain\Money\Money;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Zahlungsbetragsabweichung.
 *
 * Der serverseitig berechnete Preis muss dem tatsaechlich gezahlten Betrag
 * entsprechen. Eine Abweichung blockiert die Finalisierung.
 */
final class PaymentAmountMismatchRule extends AbstractRule
{
    protected const string CODE = 'ZAHLUNGSBETRAG_ABWEICHUNG';

    protected const string TITLE = 'Abweichender Zahlungsbetrag';

    protected const string DESCRIPTION = 'Vergleicht den serverseitig berechneten Preis mit dem tatsächlich '
        .'gezahlten Betrag.';

    protected const string REFERENCE = 'Technische Prüfung der Zahlung. Der Preis wird serverseitig berechnet, ein '
        .'Browser-Redirect ist kein Zahlungsnachweis.';

    protected const string PASSED_DESCRIPTION = 'Der gezahlte Betrag entspricht dem berechneten Preis.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::BLOCKER;
    }

    public function evaluate(RuleContext $context): array
    {
        $state = $context->finalizationState;
        $expected = $state->expectedAmount;
        $paid = $state->paidAmount;

        if (! $expected instanceof Money || ! $paid instanceof Money) {
            return [];
        }

        if ($expected->equals($paid)) {
            return [];
        }

        return [
            RuleFinding::blocker(
                sprintf(
                    'Der berechnete Preis beträgt %s, gezahlt wurden %s. Die Abweichung beträgt %s. Eine '
                    .'Finalisierung ist erst nach Klärung der Zahlung möglich.',
                    $expected->format(),
                    $paid->format(),
                    $paid->minus($expected)->format()
                ),
                'BillingRun',
                null,
                [
                    'expectedCent' => $expected->cents,
                    'paidCent' => $paid->cents,
                ]
            ),
        ];
    }
}
