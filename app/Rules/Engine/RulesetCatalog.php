<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use DateTimeImmutable;

/**
 * Katalog der Regelstaende.
 *
 * Jeder Regelstand hat eine Version und einen Gueltigkeitsbeginn. Der zu
 * einem Abrechnungslauf gehoerende Regelstand wird anhand des Beginns des
 * Abrechnungszeitraums bestimmt, weil sich der anzuwendende Regelstand nach
 * dem abzurechnenden Zeitraum richtet.
 *
 * Regelstaende werden niemals entfernt oder inhaltlich geaendert. Wird eine
 * Regel mit einem neuen Gueltigkeitsbeginn aufgenommen, ist ein neuer
 * Regelstand zu ergaenzen.
 */
final class RulesetCatalog
{
    /**
     * Alle Regelstaende, aufsteigend nach Gueltigkeitsbeginn.
     *
     * @return list<RulesetGeneration>
     */
    public static function generations(): array
    {
        return [
            new RulesetGeneration(
                '2020.1',
                '2020-01-01',
                'Grundstand der Prüfregeln für Abrechnungszeiträume ab 2020.',
            ),
            new RulesetGeneration(
                '2023.1',
                '2023-01-01',
                'Zusätzlich die Prüfung des Status der CO2-Kostenaufteilung.',
            ),
        ];
    }

    /**
     * Regelstand, der fuer einen Stichtag gilt.
     */
    public static function generationFor(DateTimeImmutable $date): RulesetGeneration
    {
        $generations = self::generations();
        $selected = $generations[0];

        foreach ($generations as $generation) {
            if ($generation->validFrom <= $date) {
                $selected = $generation;
            }
        }

        return $selected;
    }

    public static function generationByVersion(string $version): RulesetGeneration
    {
        foreach (self::generations() as $generation) {
            if ($generation->version === $version) {
                return $generation;
            }
        }

        throw UnknownRulesetVersionException::forVersion($version);
    }

    /**
     * @return list<string>
     */
    public static function versions(): array
    {
        return array_map(
            static fn (RulesetGeneration $generation): string => $generation->version,
            self::generations()
        );
    }
}
