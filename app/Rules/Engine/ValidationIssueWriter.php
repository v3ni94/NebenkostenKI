<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use App\Enums\ValidationIssueStatus;
use App\Enums\ValidationSeverity;
use App\Models\BillingRun;
use App\Models\User;
use App\Models\ValidationIssue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persistenz der Pruefergebnisse.
 *
 * Die Regel-Engine liefert Ergebnisse, diese Klasse schreibt sie. Sie wird
 * von der Anwendungsschicht aufgerufen. Bereits entschiedene Pruefaufgaben
 * bleiben erhalten, damit der Nutzer eine Warnung nicht zweimal entscheiden
 * muss.
 */
final class ValidationIssueWriter
{
    /**
     * Severities, die eine Finalisierung verhindern, solange sie offen sind.
     *
     * @var list<string>
     */
    public const array DECISION_REQUIRED_SEVERITIES = [
        'BLOCKER',
        'WARNUNG',
    ];

    /**
     * Schreibt den Prüfbericht eines Abrechnungslaufs.
     *
     * Offene Ergebnisse des Vorlaufs werden ersetzt, entschiedene Ergebnisse
     * bleiben unveraendert bestehen.
     *
     * @return list<ValidationIssue>
     */
    public function persist(BillingRun $billingRun, RuleReport $report): array
    {
        return DB::transaction(function () use ($billingRun, $report): array {
            ValidationIssue::query()
                ->where('billing_run_id', $billingRun->getKey())
                ->where('status', ValidationIssueStatus::OFFEN->value)
                ->delete();

            $decided = ValidationIssue::query()
                ->where('billing_run_id', $billingRun->getKey())
                ->where('status', '!=', ValidationIssueStatus::OFFEN->value)
                ->get();

            $written = [];
            $detectedAt = Carbon::instance($report->evaluatedAt);

            foreach ($report->results as $result) {
                if ($this->alreadyDecided($decided->all(), $result)) {
                    continue;
                }

                $attributes = $result->toIssueAttributes();

                // entity_id ist eine ULID-Spalte. Regelergebnisse ohne
                // Datenbankschluessel behalten die Entitaetsart, tragen aber
                // keine Kennung.
                if (is_string($attributes['entity_id']) && strlen($attributes['entity_id']) !== 26) {
                    $attributes['entity_id'] = null;
                }

                $written[] = ValidationIssue::query()->create(array_merge(
                    $attributes,
                    [
                        'organization_id' => $billingRun->organization_id,
                        'billing_run_id' => $billingRun->getKey(),
                        'status' => ValidationIssueStatus::OFFEN,
                        'detected_at' => $detectedAt,
                    ]
                ));
            }

            return $written;
        });
    }

    /**
     * Protokolliert eine ausdrueckliche Nutzerentscheidung zu einer Warnung.
     *
     * Blocker und nicht wegklickbare Regeln koennen nicht auf diesem Weg
     * aufgeloest werden.
     */
    public function resolveWithDecision(
        ValidationIssue $issue,
        User $user,
        string $decision,
        ValidationIssueStatus $status = ValidationIssueStatus::AKZEPTIERT,
    ): ValidationIssue {
        if ($issue->severity !== ValidationSeverity::WARNUNG) {
            throw RuleNotUserResolvableException::forCode($issue->rule_code);
        }

        $rule = RuleRegistry::find($issue->rule_code);

        if ($rule instanceof Rule && ! $rule->isUserResolvable()) {
            throw RuleNotUserResolvableException::forCode($issue->rule_code);
        }

        $issue->forceFill([
            'status' => $status,
            'resolution' => $decision,
            'resolved_by_user_id' => $user->getKey(),
            'resolved_at' => Carbon::now(),
        ])->save();

        return $issue->refresh();
    }

    /**
     * Markiert eine Pruefaufgabe als korrigiert.
     */
    public function markCorrected(ValidationIssue $issue, User $user, string $note): ValidationIssue
    {
        $issue->forceFill([
            'status' => ValidationIssueStatus::KORRIGIERT,
            'resolution' => $note,
            'resolved_by_user_id' => $user->getKey(),
            'resolved_at' => Carbon::now(),
        ])->save();

        return $issue->refresh();
    }

    /**
     * @param  array<int, ValidationIssue>  $decided
     */
    private function alreadyDecided(array $decided, RuleResult $result): bool
    {
        foreach ($decided as $issue) {
            if (
                $issue->rule_code === $result->ruleCode
                && $issue->rule_version === $result->ruleVersion
                && $issue->entity_id === $result->entityId
                && $issue->description === $result->description
            ) {
                return true;
            }
        }

        return false;
    }
}
