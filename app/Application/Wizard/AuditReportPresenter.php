<?php

declare(strict_types=1);

namespace App\Application\Wizard;

use App\Enums\ValidationIssueStatus;
use App\Enums\ValidationSeverity;
use App\Models\BillingRun;
use App\Models\User;
use App\Models\ValidationIssue;
use App\Rules\Engine\RuleContextFactory;
use App\Rules\Engine\RuleEngine;
use App\Rules\Engine\RuleReport;
use App\Rules\Engine\ValidationIssueWriter;

/**
 * Schritt 9 des geführten Ablaufs: Prüfbericht.
 *
 * Vor der Vorschau läuft die gesamte Regel-Engine. Die Ergebnisse erscheinen in
 * vier Gruppen:
 *
 *   Blocker    verhindert das Weitergehen
 *   Warnung    nur mit ausdrücklicher, protokollierter Entscheidung auflösbar
 *   Hinweis    plausibel, aber informativ
 *   Bestanden  Prüfschritt erfolgreich
 *
 * Nicht wegklickbare Regeln, etwa der Hinweis zu unvollständigen Daten im
 * Heizkostenfall B, können nicht durch eine Nutzerentscheidung aufgelöst
 * werden. Das setzt App\Rules\Engine\ValidationIssueWriter durch.
 */
final class AuditReportPresenter
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly RuleContextFactory $contexts,
        private readonly ValidationIssueWriter $issues,
    ) {}

    /**
     * Führt die Regel-Engine aus und schreibt die Prüfaufgaben.
     */
    public function run(BillingRun $billingRun): RuleReport
    {
        $report = $this->rules->runForContext($this->contexts->fromBillingRun($billingRun));

        $this->issues->persist($billingRun, $report);

        return $report;
    }

    /**
     * Die vier Gruppen des Prüfberichts aus dem gespeicherten Stand.
     *
     * @return array<string, list<ValidationIssue>>
     */
    public function groups(BillingRun $billingRun): array
    {
        $gruppen = [];

        foreach (ValidationSeverity::cases() as $severity) {
            /** @var list<ValidationIssue> $eintraege */
            $eintraege = ValidationIssue::query()
                ->where('billing_run_id', $billingRun->getKey())
                ->where('severity', $severity->value)
                ->orderBy('rule_code')
                ->get()
                ->all();

            $gruppen[$severity->value] = $eintraege;
        }

        return $gruppen;
    }

    /**
     * @return array<string, int>
     */
    public function counts(BillingRun $billingRun): array
    {
        return array_map(
            static fn (array $gruppe): int => count($gruppe),
            $this->groups($billingRun)
        );
    }

    /**
     * @return list<ValidationIssue>
     */
    public function openBlockers(BillingRun $billingRun): array
    {
        return $this->open($billingRun, ValidationSeverity::BLOCKER);
    }

    /**
     * @return list<ValidationIssue>
     */
    public function openWarnings(BillingRun $billingRun): array
    {
        return $this->open($billingRun, ValidationSeverity::WARNUNG);
    }

    /**
     * Blocker verhindern das Weitergehen zur Vorschau.
     */
    public function mayProceed(BillingRun $billingRun): bool
    {
        return $this->openBlockers($billingRun) === [];
    }

    public function blockingReason(BillingRun $billingRun): ?string
    {
        $blocker = $this->openBlockers($billingRun);

        if ($blocker === []) {
            return null;
        }

        $anzahl = count($blocker);

        return $anzahl === 1
            ? 'Es liegt 1 Ergebnis vor, das die Abrechnung blockiert. Bitte beheben Sie es, bevor die Vorschau erzeugt wird.'
            : sprintf(
                'Es liegen %d Ergebnisse vor, die die Abrechnung blockieren. Bitte beheben Sie sie, bevor die Vorschau '
                .'erzeugt wird.',
                $anzahl
            );
    }

    /**
     * Ausdrückliche Nutzerentscheidung zu einer Warnung. Sie wird mit Nutzer,
     * Zeitpunkt und Text protokolliert.
     */
    public function decide(ValidationIssue $issue, User $actor, string $entscheidung): ValidationIssue
    {
        return $this->issues->resolveWithDecision($issue, $actor, $entscheidung);
    }

    /**
     * Prüfaufgabe eines Laufs, mandantensicher geladen.
     */
    public function issue(BillingRun $billingRun, string $issueId): ?ValidationIssue
    {
        $issue = ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('organization_id', $billingRun->getAttribute('organization_id'))
            ->whereKey($issueId)
            ->first();

        return $issue instanceof ValidationIssue ? $issue : null;
    }

    /**
     * @return list<ValidationIssue>
     */
    private function open(BillingRun $billingRun, ValidationSeverity $severity): array
    {
        /** @var list<ValidationIssue> $eintraege */
        $eintraege = ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('severity', $severity->value)
            ->where('status', ValidationIssueStatus::OFFEN->value)
            ->orderBy('rule_code')
            ->get()
            ->all();

        return $eintraege;
    }
}
