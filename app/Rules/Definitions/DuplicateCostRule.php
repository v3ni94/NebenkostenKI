<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Domain\Calculation\Check\DuplicateCostDetector;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\DomainInputMapper;
use App\Rules\Engine\RuleFinding;

/**
 * Dubletten von Kostenpositionen.
 *
 * Erkannt werden doppelte Rechnungsnummer beim gleichen Lieferanten, gleicher
 * Betrag mit gleichem Belegdatum beim gleichen Lieferanten und derselbe
 * Dateifingerabdruck. Die Erkennung liegt in
 * App\Domain\Calculation\Check\DuplicateCostDetector; es wird nichts geloescht.
 */
final class DuplicateCostRule extends AbstractRule
{
    protected const string CODE = 'DUBLETTE_KOSTENPOSITION';

    protected const string TITLE = 'Mögliche Dublette';

    protected const string DESCRIPTION = 'Prüft Kostenpositionen auf doppelte Rechnungsnummer, gleichen Betrag mit '
        .'gleichem Belegdatum und identischen Dateifingerabdruck.';

    protected const string REFERENCE = 'Fachliche Dublettenprüfung. Eine erkannte Dublette wird nicht automatisch '
        .'entfernt, sondern zur Entscheidung vorgelegt.';

    protected const string PASSED_DESCRIPTION = 'Es wurden keine doppelten Kostenpositionen erkannt.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::WARNUNG;
    }

    public function evaluate(RuleContext $context): array
    {
        $detector = new DuplicateCostDetector;
        $references = DomainInputMapper::toInvoiceReferences($context->costItems);
        $findings = [];

        foreach ($detector->detect($references) as $finding) {
            $costItemKey = $finding->context['costItemKey'] ?? null;
            $duplicateOf = $finding->context['duplicateOf'] ?? null;

            $findings[] = RuleFinding::warnung(
                $finding->message.' Bitte entscheiden Sie, ob die Position doppelt erfasst ist.',
                'CostItem',
                is_string($costItemKey) ? $costItemKey : null,
                [
                    'duplicateOf' => is_string($duplicateOf) ? $duplicateOf : null,
                    'reason' => is_string($finding->context['reason'] ?? null) ? (string) $finding->context['reason'] : null,
                ]
            );
        }

        return $findings;
    }
}
