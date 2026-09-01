<?php

declare(strict_types=1);

namespace App\Application\Review;

use App\Application\Reconciliation\RuleCode;
use App\Enums\CostItemStatus;
use App\Enums\ValidationIssueStatus;
use App\Enums\ValidationSeverity;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\ValidationIssue;

/**
 * Freigabe des naechsten Schritts nach der Kostenpruefung.
 *
 * Weiter ist erst moeglich, wenn jede Kostenposition bestaetigt oder verworfen
 * ist. Offene Blocker aus der Pruefung, etwa eine Abweichung der Heizkosten
 * ueber der Toleranz oder eine fehlende Kostenaufschluesselung, verhindern die
 * Freigabe ebenfalls.
 */
final class ReviewGate
{
    public function openPositionCount(BillingRun $billingRun): int
    {
        return CostItem::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', CostItemStatus::VORGESCHLAGEN->value)
            ->count();
    }

    public function positionCount(BillingRun $billingRun): int
    {
        return CostItem::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->count();
    }

    /**
     * @return list<ValidationIssue>
     */
    public function openBlockers(BillingRun $billingRun): array
    {
        $issues = ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->openBlockers()
            ->orderBy('detected_at')
            ->get()
            ->all();

        return array_values($issues);
    }

    public function mayProceed(BillingRun $billingRun): bool
    {
        return $this->reason($billingRun) === null;
    }

    /**
     * Grund der Sperre in verstaendlichem Deutsch, oder null.
     */
    public function reason(BillingRun $billingRun): ?string
    {
        if ($this->positionCount($billingRun) === 0) {
            return 'Es liegt noch keine Kostenposition vor. Bitte laden Sie Unterlagen hoch oder erfassen Sie eine '
                .'Position manuell.';
        }

        $open = $this->openPositionCount($billingRun);

        if ($open > 0) {
            return sprintf(
                'Es sind noch %d Kostenpositionen offen. Bitte bestätigen oder verwerfen Sie jede Position, bevor '
                .'Sie fortfahren.',
                $open
            );
        }

        $blockers = $this->openBlockers($billingRun);

        if ($blockers !== []) {
            return sprintf(
                'Es sind noch %d Punkte offen, die die Abrechnung blockieren. Bitte klären Sie diese Punkte: %s',
                count($blockers),
                implode(' ', array_map(
                    static fn (ValidationIssue $issue): string => (string) $issue->getAttribute('title'),
                    array_slice($blockers, 0, 5)
                ))
            );
        }

        return null;
    }

    /**
     * Vermerkt die Sperre als Pruefaufgabe, damit sie auch im Prüfbericht
     * erscheint.
     */
    public function recordBlockingIssue(BillingRun $billingRun): ?ValidationIssue
    {
        $open = $this->openPositionCount($billingRun);

        if ($open === 0) {
            return null;
        }

        $existing = ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('rule_code', RuleCode::REVIEW_INCOMPLETE)
            ->first();

        if ($existing instanceof ValidationIssue) {
            return $existing;
        }

        $issue = new ValidationIssue;

        $issue->fill([
            'organization_id' => $billingRun->getAttribute('organization_id'),
            'billing_run_id' => $billingRun->getKey(),
            'rule_code' => RuleCode::REVIEW_INCOMPLETE,
            'rule_version' => RuleCode::VERSION,
            'severity' => ValidationSeverity::BLOCKER,
            'status' => ValidationIssueStatus::OFFEN,
            'blocks_finalization' => true,
            'title' => 'Kostenprüfung nicht abgeschlossen',
            'description' => sprintf(
                'Es sind noch %d Kostenpositionen offen. Jede Position muss bestätigt oder verworfen werden.',
                $open
            ),
            'detected_at' => now(),
        ]);

        $issue->save();

        return $issue;
    }
}
