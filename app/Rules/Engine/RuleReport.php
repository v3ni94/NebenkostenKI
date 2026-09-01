<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use App\Enums\ValidationSeverity;
use DateTimeImmutable;

/**
 * Prüfbericht nach Abschnitt 9, Schritt 9 des Pflichtenhefts.
 *
 * Die Ergebnisse werden in vier Gruppen ausgegeben: Blocker, Warnung, Hinweis
 * und Bestanden. Ein Blocker verhindert die Finalisierung. Eine Warnung
 * erfordert eine ausdrückliche Nutzerentscheidung. Bestandene Prüfschritte
 * werden ebenfalls ausgegeben, damit der Nutzer sieht, was geprüft wurde.
 */
final readonly class RuleReport
{
    /**
     * @param  list<RuleResult>  $results
     */
    public function __construct(
        public string $rulesetVersion,
        public DateTimeImmutable $evaluatedAt,
        public array $results,
    ) {}

    /**
     * @return list<RuleResult>
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * @return list<RuleResult>
     */
    public function blockers(): array
    {
        return $this->bySeverity(ValidationSeverity::BLOCKER);
    }

    /**
     * @return list<RuleResult>
     */
    public function warnungen(): array
    {
        return $this->bySeverity(ValidationSeverity::WARNUNG);
    }

    /**
     * @return list<RuleResult>
     */
    public function hinweise(): array
    {
        return $this->bySeverity(ValidationSeverity::HINWEIS);
    }

    /**
     * @return list<RuleResult>
     */
    public function bestanden(): array
    {
        return $this->bySeverity(ValidationSeverity::BESTANDEN);
    }

    /**
     * Die vier Gruppen des Prüfberichts in fester Reihenfolge.
     *
     * @return array<string, list<RuleResult>>
     */
    public function grouped(): array
    {
        return [
            ValidationSeverity::BLOCKER->value => $this->blockers(),
            ValidationSeverity::WARNUNG->value => $this->warnungen(),
            ValidationSeverity::HINWEIS->value => $this->hinweise(),
            ValidationSeverity::BESTANDEN->value => $this->bestanden(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function groupCounts(): array
    {
        return array_map(static fn (array $group): int => count($group), $this->grouped());
    }

    public function blocksFinalization(): bool
    {
        return $this->blockers() !== [];
    }

    /**
     * Regelcodes, die eine ausdrückliche Nutzerentscheidung erfordern.
     *
     * @return list<string>
     */
    public function warningCodesRequiringDecision(): array
    {
        $codes = [];

        foreach ($this->warnungen() as $result) {
            if (! in_array($result->ruleCode, $codes, true)) {
                $codes[] = $result->ruleCode;
            }
        }

        return $codes;
    }

    /**
     * @return list<RuleResult>
     */
    private function bySeverity(ValidationSeverity $severity): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (RuleResult $result): bool => $result->severity === $severity
        ));
    }
}
