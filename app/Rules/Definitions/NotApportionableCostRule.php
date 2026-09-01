<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ApportionmentStatus;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Nicht umlagefaehige Kosten in der Umlage.
 *
 * Standardmaessig nicht umlagefaehige und pruefpflichtige Positionen werden
 * aus der Mieterumlage ausgeschlossen. Eine bewusste Einbeziehung erfordert
 * eine Begruendung und wird deutlich gekennzeichnet. Sie ist keine
 * juristische Freigabe.
 */
final class NotApportionableCostRule extends AbstractRule
{
    protected const string CODE = 'NICHT_UMLAGEFAEHIGE_KOSTEN';

    protected const string TITLE = 'Nicht umlagefähige Kosten in der Umlage';

    protected const string DESCRIPTION = 'Prüft, ob Positionen mit dem Status nicht umlagefähig oder prüfpflichtig '
        .'in die Mieterumlage einbezogen sind.';

    protected const string REFERENCE = 'Allgemeine Information: Verwaltungskosten, Instandhaltung, Reparaturen, '
        .'Bankkosten, Rechtskosten und Rücklagenzuführung gelten regelmäßig nicht als umlagefähige Betriebskosten. '
        .'Die Bewertung im Einzelfall ist rechtlich zu prüfen.';

    protected const string PASSED_DESCRIPTION = 'Es sind keine nicht umlagefähigen oder prüfpflichtigen Positionen '
        .'in der Mieterumlage enthalten.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::WARNUNG;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->apportionedCostItems() as $item) {
            if ($item->apportionmentStatus === ApportionmentStatus::UMLAGEFAEHIG) {
                continue;
            }

            $statusLabel = $item->apportionmentStatus === ApportionmentStatus::NICHT_UMLAGEFAEHIG
                ? 'nicht umlagefähig'
                : 'prüfpflichtig';

            $reason = $item->apportionmentOverrideReason;

            $findings[] = RuleFinding::warnung(
                sprintf(
                    'Die Position "%s" (%s) ist als %s eingeordnet und dennoch in der Mieterumlage enthalten. %s '
                    .'Diese Einbeziehung ist keine rechtliche Freigabe und von Ihnen zu verantworten.',
                    $item->description,
                    $item->amount->format(),
                    $statusLabel,
                    $reason !== null && trim($reason) !== ''
                        ? sprintf('Begründung: %s.', trim($reason))
                        : 'Eine Begründung fehlt.',
                ),
                'CostItem',
                $item->key,
                [
                    'apportionmentStatus' => $item->apportionmentStatus->value,
                    'hasReason' => $reason !== null && trim($reason) !== '',
                ]
            );
        }

        return $findings;
    }
}
