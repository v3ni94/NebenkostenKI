<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use App\Enums\ValidationIssueStatus;
use App\Models\BillingRun;
use App\Models\ValidationIssue;

/**
 * Freigabepruefung vor der Finalisierung.
 *
 * Ein Blocker verhindert die Finalisierung. Eine Warnung verhindert sie
 * ebenfalls, solange keine ausdrueckliche Nutzerentscheidung protokolliert
 * ist.
 */
final class FinalizationGate
{
    /**
     * Darf auf Grundlage des Prüfberichts finalisiert werden?
     */
    public function allowsByReport(RuleReport $report): bool
    {
        return ! $report->blocksFinalization() && $report->warnungen() === [];
    }

    /**
     * Gruende, die eine Finalisierung nach dem Prüfbericht verhindern.
     *
     * @return list<string>
     */
    public function reasonsByReport(RuleReport $report): array
    {
        $reasons = [];

        foreach ($report->blockers() as $result) {
            $reasons[] = sprintf('Blocker %s: %s', $result->ruleCode, $result->title);
        }

        foreach ($report->warnungen() as $result) {
            $reasons[] = sprintf('Offene Warnung %s: %s', $result->ruleCode, $result->title);
        }

        return $reasons;
    }

    /**
     * Darf ein Abrechnungslauf nach dem persistierten Stand finalisiert
     * werden? Offene Blocker und offene Warnungen verhindern die Freigabe.
     */
    public function allowsBillingRun(BillingRun $billingRun): bool
    {
        return $this->openIssueQuery($billingRun) === 0;
    }

    /**
     * Offene Pruefaufgaben, die eine Finalisierung verhindern.
     *
     * @return list<ValidationIssue>
     */
    public function blockingIssues(BillingRun $billingRun): array
    {
        /** @var list<ValidationIssue> $issues */
        $issues = ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', ValidationIssueStatus::OFFEN->value)
            ->whereIn('severity', ValidationIssueWriter::DECISION_REQUIRED_SEVERITIES)
            ->orderBy('rule_code')
            ->get()
            ->all();

        return $issues;
    }

    private function openIssueQuery(BillingRun $billingRun): int
    {
        return ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', ValidationIssueStatus::OFFEN->value)
            ->whereIn('severity', ValidationIssueWriter::DECISION_REQUIRED_SEVERITIES)
            ->count();
    }
}
