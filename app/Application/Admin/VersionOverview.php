<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Models\CalculationSnapshot;
use App\Rules\Engine\Ruleset;
use App\Rules\Engine\RulesetCatalog;
use App\Rules\Engine\RulesetGeneration;

/**
 * Regel- und Promptversionen (Masterprompt 12, 13, 20).
 *
 * VERBINDLICH: Eine Adminaenderung an Regel oder Prompt wirkt ausschliesslich
 * auf neue Berechnungsstaende. Diese Uebersicht macht sichtbar, welche
 * Regelstaende in bereits gespeicherten Berechnungsstaenden verwendet wurden.
 * Sie aendert nichts.
 */
final class VersionOverview
{
    /**
     * Alle hinterlegten Regelstaende mit Anzahl der Regeln und der Verwendung
     * in gespeicherten Berechnungsstaenden.
     *
     * @return list<array{
     *     version: string,
     *     gueltig_ab: string,
     *     hinweis: string,
     *     regelanzahl: int,
     *     verwendet_in_staenden: int
     * }>
     */
    public function rulesets(): array
    {
        $rows = [];

        /** @var RulesetGeneration $generation */
        foreach (RulesetCatalog::generations() as $generation) {
            $ruleset = Ruleset::fromVersion($generation->version);

            $rows[] = [
                'version' => $generation->version,
                'gueltig_ab' => $generation->validFrom->format('d.m.Y'),
                'hinweis' => $generation->note,
                'regelanzahl' => $ruleset->count(),
                'verwendet_in_staenden' => CalculationSnapshot::query()
                    ->where('ruleset_version', $generation->version)
                    ->count(),
            ];
        }

        return $rows;
    }

    /**
     * Regelcodes eines Regelstands, nur zur Nachvollziehbarkeit.
     *
     * @return list<string>
     */
    public function ruleCodes(string $version): array
    {
        return Ruleset::fromVersion($version)->ruleCodes();
    }

    /**
     * In gespeicherten Berechnungsstaenden verwendete Domainversionen.
     *
     * @return array<string, int>
     */
    public function domainVersions(): array
    {
        /** @var array<int|string, mixed> $raw */
        $raw = CalculationSnapshot::query()
            ->selectRaw('domain_version, count(*) as anzahl')
            ->groupBy('domain_version')
            ->orderBy('domain_version')
            ->pluck('anzahl', 'domain_version')
            ->all();

        $versions = [];

        foreach ($raw as $version => $count) {
            $versions[(string) $version] = is_numeric($count) ? (int) $count : 0;
        }

        return $versions;
    }
}
