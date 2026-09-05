<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use App\Rules\Context\RuleContext;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Die Regel-Engine.
 *
 * Sie erhaelt einen Regelstand und die validierten Eingabedaten eines
 * Abrechnungslaufs und liefert einen Prüfbericht mit den vier Gruppen
 * Blocker, Warnung, Hinweis und Bestanden. Die Engine schreibt nicht in die
 * Datenbank; die Persistenz erfolgt ueber
 * App\Rules\Engine\ValidationIssueWriter.
 */
final class RuleEngine
{
    public function run(Ruleset $ruleset, RuleContext $context): RuleReport
    {
        $results = [];

        foreach ($ruleset->rules as $rule) {
            $findings = $rule->evaluate($context);

            if ($findings === []) {
                $results[] = RuleResult::passed($rule);

                continue;
            }

            foreach ($findings as $finding) {
                $results[] = RuleResult::fromFinding($rule, $finding);
            }
        }

        return new RuleReport($ruleset->version, $this->now(), $results);
    }

    /**
     * Prueft einen Abrechnungslauf mit dem Regelstand seines
     * Abrechnungszeitraums.
     */
    public function runForContext(RuleContext $context): RuleReport
    {
        return $this->run(Ruleset::forContext($context), $context);
    }

    /**
     * Wiederholt eine Pruefung mit einem gespeicherten Regelstand.
     */
    public function reproduce(string $rulesetVersion, RuleContext $context): RuleReport
    {
        return $this->run(Ruleset::fromVersion($rulesetVersion), $context);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
