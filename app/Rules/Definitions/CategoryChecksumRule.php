<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Belegsumme gegen Kategoriensumme.
 *
 * Eine Abweichung oberhalb der konfigurierten Toleranz blockiert die
 * Finalisierung, bis der Nutzer sie erklaert oder korrigiert.
 */
final class CategoryChecksumRule extends AbstractRule
{
    protected const string CODE = 'BELEGSUMME_GEGEN_KATEGORIENSUMME';

    protected const string TITLE = 'Belegsumme gegen Kategoriensumme';

    protected const string DESCRIPTION = 'Vergleicht die Summe der Einzelbelege einer Kostenart mit der '
        .'ausgewiesenen Kategoriensumme.';

    protected const string REFERENCE = 'Technische Prüfsumme der Berechnung. Toleranz aus '
        .'config(smartabrechnen.tolerances.checksum_cent).';

    protected const string PASSED_DESCRIPTION = 'Die Summe der Einzelbelege stimmt in allen Kostenarten mit der '
        .'ausgewiesenen Kategoriensumme im Rahmen der Toleranz überein.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::BLOCKER;
    }

    public function evaluate(RuleContext $context): array
    {
        $tolerance = $context->tolerances->checksum();
        $findings = [];

        foreach ($context->categoryChecksums as $checksum) {
            $difference = $checksum->difference();

            if ($difference->absolute()->compareTo($tolerance) <= 0) {
                continue;
            }

            $findings[] = RuleFinding::blocker(
                sprintf(
                    'In der Kostenart "%s" weicht die Summe der Einzelbelege (%s) um %s von der ausgewiesenen '
                    .'Kategoriensumme (%s) ab. Die Toleranz von %s ist überschritten. Eine Finalisierung ist erst '
                    .'nach Klärung möglich.',
                    $checksum->categoryLabel,
                    $checksum->sumOfDocuments->format(),
                    $difference->format(),
                    $checksum->declaredTotal->format(),
                    $tolerance->format()
                ),
                'CostCategory',
                $checksum->categoryKey,
                [
                    'differenceCent' => $difference->cents,
                    'toleranceCent' => $tolerance->cents,
                ]
            );
        }

        return $findings;
    }
}
