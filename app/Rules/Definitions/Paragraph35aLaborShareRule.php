<?php

declare(strict_types=1);

namespace App\Rules\Definitions;

use App\Enums\Paragraph35aType;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\RuleFinding;

/**
 * Beguenstigte Kategorie ohne ausgewiesenen Lohnanteil.
 *
 * Es wird ausdruecklich nicht geschaetzt. Die Position erhaelt einen Hinweis,
 * damit der Nutzer den Lohnanteil nachtraegt oder die Anlage ohne diese
 * Position erstellt.
 */
final class Paragraph35aLaborShareRule extends AbstractRule
{
    protected const string CODE = 'PARAGRAF_35A_LOHNANTEIL_FEHLT';

    protected const string TITLE = 'Lohnanteil nicht ausgewiesen';

    protected const string DESCRIPTION = 'Prüft, ob bei Positionen mit begünstigter Kategorie ein Lohnanteil '
        .'ausgewiesen ist. Fehlt er, erfolgt ein Hinweis und keine Schätzung.';

    protected const string REFERENCE = 'Allgemeine Information zu haushaltsnahen Dienstleistungen und '
        .'Handwerkerleistungen: Begünstigt sind nur nachgewiesene Arbeits-, Maschinen- und Fahrtkosten. '
        .'Materialkosten werden nicht als Lohnanteil ausgegeben.';

    protected const string PASSED_DESCRIPTION = 'Für alle Positionen mit begünstigter Kategorie ist ein Lohnanteil '
        .'ausgewiesen.';

    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::HINWEIS;
    }

    public function evaluate(RuleContext $context): array
    {
        $findings = [];

        foreach ($context->costItems as $item) {
            if ($item->paragraph35aType === Paragraph35aType::NONE) {
                continue;
            }

            if ($item->laborShareCent !== null) {
                continue;
            }

            $findings[] = RuleFinding::hinweis(
                sprintf(
                    'Die Position "%s" (%s) gehört zu einer begünstigten Kategorie, ein Lohnanteil ist jedoch '
                    .'nicht ausgewiesen. Der Anteil wird nicht geschätzt. Bitte reichen Sie eine Rechnung mit '
                    .'ausgewiesenem Lohnanteil nach, wenn die Position in der Anlage erscheinen soll.',
                    $item->description,
                    $item->amount->format()
                ),
                'CostItem',
                $item->key,
                ['paragraph35aType' => $item->paragraph35aType->value]
            );
        }

        return $findings;
    }
}
