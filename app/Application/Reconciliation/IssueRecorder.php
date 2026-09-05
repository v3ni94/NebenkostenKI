<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Enums\ValidationIssueStatus;
use App\Enums\ValidationSeverity;
use App\Models\BillingRun;
use App\Models\ValidationIssue;
use Illuminate\Support\Carbon;

/**
 * Schreibt die Pruefaufgaben der Reconciliation.
 *
 * Eine Pruefaufgabe ersetzt niemals eine Schaetzung. Sie ist der einzige
 * zulaessige Umgang mit einer fehlenden oder widerspruechlichen Angabe
 * (Grundsatz 5).
 *
 * Die Texte sind deutsch, in Sie-Ansprache und ohne Rechtsberatung im
 * Einzelfall.
 */
final class IssueRecorder
{
    /**
     * Entfernt die offenen eigenen Aufgaben eines Laufs. Vom Nutzer bereits
     * entschiedene Aufgaben bleiben erhalten, damit eine Entscheidung nicht
     * durch einen erneuten Lauf verloren geht.
     */
    public function clearOpen(BillingRun $billingRun): void
    {
        ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->whereIn('rule_code', RuleCode::all())
            ->where('status', ValidationIssueStatus::OFFEN->value)
            ->delete();
    }

    public function record(
        BillingRun $billingRun,
        string $ruleCode,
        ValidationSeverity $severity,
        string $title,
        string $description,
        ?string $entityType = null,
        ?string $entityId = null,
        bool $blocksFinalization = false,
        ?string $legalReference = null,
    ): ValidationIssue {
        $issue = new ValidationIssue;

        $issue->fill([
            'organization_id' => $billingRun->getAttribute('organization_id'),
            'billing_run_id' => $billingRun->getKey(),
            'rule_code' => mb_substr($ruleCode, 0, 80),
            'rule_version' => RuleCode::VERSION,
            'severity' => $severity,
            'status' => ValidationIssueStatus::OFFEN,
            'blocks_finalization' => $blocksFinalization,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'title' => mb_substr($title, 0, 190),
            'description' => $description,
            'legal_reference' => $legalReference,
            'detected_at' => Carbon::now(),
        ]);

        $issue->save();

        return $issue;
    }

    public function warning(
        BillingRun $billingRun,
        string $ruleCode,
        string $title,
        string $description,
        ?string $entityType = null,
        ?string $entityId = null,
    ): ValidationIssue {
        return $this->record(
            $billingRun,
            $ruleCode,
            ValidationSeverity::WARNUNG,
            $title,
            $description,
            $entityType,
            $entityId,
        );
    }

    public function hint(
        BillingRun $billingRun,
        string $ruleCode,
        string $title,
        string $description,
        ?string $entityType = null,
        ?string $entityId = null,
    ): ValidationIssue {
        return $this->record(
            $billingRun,
            $ruleCode,
            ValidationSeverity::HINWEIS,
            $title,
            $description,
            $entityType,
            $entityId,
        );
    }

    public function blocker(
        BillingRun $billingRun,
        string $ruleCode,
        string $title,
        string $description,
        ?string $entityType = null,
        ?string $entityId = null,
    ): ValidationIssue {
        return $this->record(
            $billingRun,
            $ruleCode,
            ValidationSeverity::BLOCKER,
            $title,
            $description,
            $entityType,
            $entityId,
            true,
        );
    }
}
