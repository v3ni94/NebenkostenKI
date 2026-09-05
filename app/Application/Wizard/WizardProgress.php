<?php

declare(strict_types=1);

namespace App\Application\Wizard;

use App\Application\BillingRun\PortalStatusCategory;
use App\Application\Wizard\Dto\WizardStepView;
use App\Enums\BillingRunStatus;
use App\Enums\CostItemStatus;
use App\Models\BillingRun;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Rahmen des geführten Ablaufs: Fortschrittsleiste und Schrittpersistenz.
 *
 * Der erreichte Schritt wird im Abrechnungslauf gespeichert. Der Nutzer kann
 * jederzeit unterbrechen und ohne Datenverlust fortsetzen. Die
 * Zurück-Navigation ist immer erlaubt; jeder Schritt speichert über eine
 * normale POST-Route mit anschließender Weiterleitung und Statusmeldung.
 *
 * Die Statussprache ist verbindlich: Erledigt, Bitte prüfen, Fehlt noch,
 * Blockiert die Abrechnung. Ein Status wird nie allein über Farbe
 * kommuniziert.
 */
final class WizardProgress
{
    public function __construct(
        private readonly PrepaymentWorkspace $prepayments,
        private readonly AllocationKeyWorkspace $keys,
        private readonly AuditReportPresenter $report,
        private readonly PreviewBuilder $preview,
        private readonly ReviewConfirmation $confirmation,
    ) {}

    /**
     * Speichert den erreichten Schritt. Ein bereits weiter fortgeschrittener
     * Stand wird nicht zurückgesetzt, damit die Zurück-Navigation keine Daten
     * und keinen Fortschritt verliert.
     */
    public function remember(BillingRun $billingRun, WizardStep $step): void
    {
        $aktuell = $billingRun->getAttribute('wizard_step');

        if (is_int($aktuell) && $aktuell >= $step->value) {
            return;
        }

        $billingRun->forceFill(['wizard_step' => $step->value])->save();
    }

    public function currentStep(BillingRun $billingRun): WizardStep
    {
        $wert = $billingRun->getAttribute('wizard_step');

        return WizardStep::tryFrom(is_int($wert) ? $wert : 1) ?? WizardStep::KONTO_UND_ZEITRAUM;
    }

    /**
     * Fortschrittsleiste über alle zwölf Schritte.
     *
     * @return list<WizardStepView>
     */
    public function bar(BillingRun $billingRun, ?WizardStep $active = null): array
    {
        $erreicht = $this->currentStep($billingRun);
        $aktiv = $active ?? $erreicht;
        $views = [];

        foreach (WizardStep::all() as $step) {
            $views[] = new WizardStepView(
                $step,
                $this->category($billingRun, $step, $erreicht),
                $step->value <= max($erreicht->value, $aktiv->value) || $this->reachableByStatus($billingRun, $step),
                $step === $aktiv,
                $step->hint(),
            );
        }

        return $views;
    }

    /**
     * Zahlung und Finalisierung sind über den Laufstatus erreichbar, nicht
     * über die gespeicherte Schrittnummer.
     */
    private function reachableByStatus(BillingRun $billingRun, WizardStep $step): bool
    {
        $status = $billingRun->getAttribute('status');

        if (! $status instanceof BillingRunStatus) {
            return false;
        }

        return match ($step) {
            WizardStep::ZAHLUNG => $status === BillingRunStatus::CHECKOUT_PENDING
                || $status->isPaid()
                || ($status === BillingRunStatus::PREVIEW_READY && $this->checkoutReady($billingRun)),
            WizardStep::ABSCHLUSS => $status === BillingRunStatus::FINALIZED,
            default => false,
        };
    }

    private function category(BillingRun $billingRun, WizardStep $step, WizardStep $erreicht): string
    {
        $status = $billingRun->getAttribute('status');
        $status = $status instanceof BillingRunStatus ? $status : BillingRunStatus::DRAFT;

        return match ($step) {
            WizardStep::ZAHLUNG => $status->isPaid()
                ? PortalStatusCategory::ERLEDIGT
                : ($status === BillingRunStatus::CHECKOUT_PENDING
                    ? PortalStatusCategory::BITTE_PRUEFEN
                    : PortalStatusCategory::FEHLT_NOCH),
            WizardStep::ABSCHLUSS => $status === BillingRunStatus::FINALIZED
                ? PortalStatusCategory::ERLEDIGT
                : PortalStatusCategory::FEHLT_NOCH,
            WizardStep::VORAUSZAHLUNGEN => $this->prepayments->isComplete($billingRun)
                ? PortalStatusCategory::ERLEDIGT
                : PortalStatusCategory::FEHLT_NOCH,
            WizardStep::VERTEILERSCHLUESSEL => $this->keys->isComplete($billingRun)
                ? ($this->keys->warnings($billingRun) === []
                    ? PortalStatusCategory::ERLEDIGT
                    : PortalStatusCategory::BITTE_PRUEFEN)
                : PortalStatusCategory::BLOCKIERT,
            WizardStep::PRUEFBERICHT => $this->reportCategory($billingRun),
            WizardStep::VORSCHAU => $this->preview->isValid($billingRun)
                ? ($this->confirmation->isConfirmed($billingRun)
                    ? PortalStatusCategory::ERLEDIGT
                    : PortalStatusCategory::BITTE_PRUEFEN)
                : PortalStatusCategory::FEHLT_NOCH,
            WizardStep::UPLOAD => $this->uploadCategory($billingRun),
            WizardStep::KOSTENPRUEFUNG => $this->costCategory($billingRun),
            default => $step->value < $erreicht->value
                ? PortalStatusCategory::ERLEDIGT
                : ($step->value === $erreicht->value
                    ? PortalStatusCategory::BITTE_PRUEFEN
                    : PortalStatusCategory::FEHLT_NOCH),
        };
    }

    private function reportCategory(BillingRun $billingRun): string
    {
        if ($this->report->openBlockers($billingRun) !== []) {
            return PortalStatusCategory::BLOCKIERT;
        }

        if ($this->report->openWarnings($billingRun) !== []) {
            return PortalStatusCategory::BITTE_PRUEFEN;
        }

        return array_sum($this->report->counts($billingRun)) === 0
            ? PortalStatusCategory::FEHLT_NOCH
            : PortalStatusCategory::ERLEDIGT;
    }

    private function uploadCategory(BillingRun $billingRun): string
    {
        $anzahl = Document::query()->where('billing_run_id', $billingRun->getKey())->count();

        return $anzahl > 0 ? PortalStatusCategory::ERLEDIGT : PortalStatusCategory::FEHLT_NOCH;
    }

    private function costCategory(BillingRun $billingRun): string
    {
        $offen = DB::table('cost_items')
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', CostItemStatus::VORGESCHLAGEN->value)
            ->count();

        if ($offen > 0) {
            return PortalStatusCategory::BITTE_PRUEFEN;
        }

        $bestaetigt = DB::table('cost_items')
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', CostItemStatus::BESTAETIGT->value)
            ->count();

        return $bestaetigt > 0 ? PortalStatusCategory::ERLEDIGT : PortalStatusCategory::FEHLT_NOCH;
    }

    /**
     * Statusmeldung für den Wiedereinstieg nach einer Unterbrechung.
     *
     * Der Hinweis erscheint nur, wenn die aufgerufene Seite nicht der zuletzt
     * gespeicherte Schritt ist. Auf der Seite des gespeicherten Schritts selbst
     * gibt es nichts wiederaufzunehmen; ein Satz "Sie sind bei Schritt 10"
     * neben der Zeile "Schritt 7 von 12" würde sich widersprechen.
     */
    public function resumeHint(BillingRun $billingRun, ?WizardStep $angezeigt = null): ?string
    {
        $step = $this->currentStep($billingRun);

        if ($angezeigt === $step) {
            return null;
        }

        return sprintf(
            'Ihr zuletzt gespeicherter Stand ist Schritt %d von %d: %s. %s',
            $step->value,
            WizardStep::count(),
            $step->label(),
            $step->hint()
        );
    }

    /**
     * Schritt, mit dem der Nutzer von der Detailseite aus weiterarbeitet.
     *
     * Grundlage ist der gespeicherte Schritt. Der Laufstatus hebt ihn an,
     * wenn ein Schritt bereits fachlich abgeschlossen ist, ohne dass seine
     * Seite besucht wurde (etwa die Kostenprüfung, die auf die Detailseite
     * zurückführt). Schritte ohne eigene Seite (1, 4 und 5) verweisen auf die
     * Detailseite selbst und werden deshalb auf den nächsten Schritt mit
     * eigener Seite weitergeschaltet.
     */
    public function resumeStep(BillingRun $billingRun): WizardStep
    {
        $step = $this->currentStep($billingRun);
        $status = $billingRun->getAttribute('status');

        $minimum = match ($status) {
            BillingRunStatus::EXTRACTING => WizardStep::ANALYSE,
            BillingRunStatus::REVIEW_REQUIRED => WizardStep::KOSTENPRUEFUNG,
            BillingRunStatus::READY_FOR_CALCULATION,
            BillingRunStatus::CALCULATED => WizardStep::VORAUSZAHLUNGEN,
            BillingRunStatus::PREVIEW_READY => WizardStep::VORSCHAU,
            default => WizardStep::UPLOAD,
        };

        if ($step->value < $minimum->value) {
            $step = $minimum;
        }

        while ($step->routeName() === 'portal.abrechnungen.show') {
            $next = $step->next();

            if ($next === null) {
                break;
            }

            $step = $next;
        }

        return $step;
    }

    /**
     * Liegt eine gültige und bestätigte Vorschau vor, kann der Nutzer zur
     * Zahlung fortfahren.
     */
    public function checkoutReady(BillingRun $billingRun): bool
    {
        return $this->preview->isValid($billingRun) && $this->confirmation->isConfirmed($billingRun);
    }
}
