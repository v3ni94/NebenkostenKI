<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\GermanDate;
use App\Rules\Engine\RuleFinding;

/**
 * Fehlende Zustellanschrift bei ausgezogenem Mieter.
 *
 * Ohne Zustellanschrift kann die Abrechnung den ausgezogenen Mieter nicht
 * erreichen. Die Finalisierung ist gesperrt.
 */
final class MissingDeliveryAddressRule extends AbstractRule
{
    protected const string CODE = 'ZUSTELLANSCHRIFT_FEHLT';

    protected const string TITLE = 'Fehlende Zustellanschrift';

    protected const string DESCRIPTION = 'Prüft, ob für ein im Abrechnungszeitraum beendetes Mietverhältnis eine '
        .'Zustellanschrift vorliegt.';

    protected const string REFERENCE = 'Fachliche Prüfung der Empfängerangaben. Die Abrechnung muss den Mieter '
        .'erreichen können.';

    protected const string PASSED_DESCRIPTION = 'Für alle beendeten Mietverhältnisse liegt eine Zustellanschrift '
        .'vor.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::BLOCKER;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->tenancies as $tenancy) {
            if (! $tenancy->hasMovedOut || $tenancy->hasDeliveryAddress) {
                continue;
            }

            $findings[] = RuleFinding::blocker(
                sprintf(
                    'Das Mietverhältnis von %s endete am %s. Es ist keine Zustellanschrift erfasst. Bitte '
                    .'ergänzen Sie die Anschrift, damit die Abrechnung zugestellt werden kann.',
                    $tenancy->displayName,
                    GermanDate::day($tenancy->period->end)
                ),
                'Tenancy',
                $tenancy->key,
                ['unitKey' => $tenancy->unitKey]
            );
        }

        return $findings;
    }
}
