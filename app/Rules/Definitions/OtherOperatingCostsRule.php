<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * "Sonstige Betriebskosten" ohne erkannte Vertragsgrundlage.
 *
 * Die Kategorie setzt eine konkrete Vereinbarung voraus. Ist im Mietvertrag
 * keine Grundlage erkannt, wird gewarnt.
 */
final class OtherOperatingCostsRule extends AbstractRule
{
    protected const string CODE = 'SONSTIGE_BETRIEBSKOSTEN_OHNE_VEREINBARUNG';

    protected const string TITLE = 'Sonstige Betriebskosten ohne Vertragsgrundlage';

    protected const string DESCRIPTION = 'Prüft, ob für Positionen der Kategorie sonstige Betriebskosten eine '
        .'konkrete Vertragsgrundlage erkannt wurde.';

    protected const string REFERENCE = 'Allgemeine Information: Sonstige Betriebskosten setzen regelmäßig eine '
        .'konkrete Vereinbarung im Mietvertrag voraus. Die Bewertung im Einzelfall ist rechtlich zu prüfen.';

    protected const string PASSED_DESCRIPTION = 'Für alle Positionen der Kategorie sonstige Betriebskosten ist eine '
        .'Vertragsgrundlage erkannt.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::WARNUNG;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->apportionedCostItems() as $item) {
            if (! $item->isOtherOperatingCosts || $item->contractBasisRecognized) {
                continue;
            }

            $findings[] = RuleFinding::warnung(
                sprintf(
                    'Für die Position "%s" (%s) in der Kategorie sonstige Betriebskosten ist keine '
                    .'Vertragsgrundlage erkannt. Bitte prüfen Sie den Mietvertrag und entscheiden Sie über die '
                    .'Umlage.',
                    $item->description,
                    $item->amount->format()
                ),
                'CostItem',
                $item->key,
                ['categoryKey' => $item->categoryKey]
            );
        }

        foreach ($context->tenancies as $tenancy) {
            if ($tenancy->otherOperatingCostsAgreed !== false) {
                continue;
            }

            $findings[] = RuleFinding::warnung(
                sprintf(
                    'Im Mietverhältnis von %s ist keine Vereinbarung über sonstige Betriebskosten erfasst. Bitte '
                    .'prüfen Sie, welche Positionen umgelegt werden dürfen.',
                    $tenancy->displayName
                ),
                'Tenancy',
                $tenancy->key,
                ['unitKey' => $tenancy->unitKey]
            );
        }

        return $findings;
    }
}
