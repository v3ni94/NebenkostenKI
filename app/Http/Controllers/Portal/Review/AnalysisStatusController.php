<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Review;

use App\Application\Reconciliation\BillingModeAdvisor;
use App\Application\Reconciliation\ReconcileBillingRun;
use App\Application\Review\AnalysisProgressReporter;
use App\Application\Wizard\WizardProgress;
use App\Application\Wizard\WizardStep;
use App\Http\Controllers\Controller;
use App\Listeners\SendProcessingStatusMails;
use App\Models\BillingRun;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Statusseite der automatischen Analyse (Schritt 3).
 *
 * Die Seite nennt konkrete Fortschrittsangaben. Providernamen und technische
 * Fehlercodes erscheinen nicht. Sie nennt ehrlich die Aufloesung des
 * Schedulers (ADR-006): laeuft die Verarbeitung nur im Cron-Intervall, wird
 * die Wartezeit als solche benannt.
 */
class AnalysisStatusController extends Controller
{
    public function __construct(
        private readonly AnalysisProgressReporter $reporter,
        private readonly ReconcileBillingRun $reconcile,
        private readonly BillingModeAdvisor $advisor,
        private readonly WizardProgress $progress,
        private readonly SendProcessingStatusMails $statusmails,
    ) {}

    public function show(BillingRun $billingRun): View
    {
        Gate::authorize('view', $billingRun);

        $this->progress->remember($billingRun, WizardStep::ANALYSE);

        return view('portal.pruefung.analyse', [
            'billingRun' => $billingRun,
            'fortschritt' => $this->reporter->report($billingRun),
            'wegvorschlag' => $this->advisor->suggest($billingRun),
            'intervallMinuten' => self::schedulerIntervalMinutes(),
            'schritte' => $this->progress->bar($billingRun, WizardStep::ANALYSE),
            'wiedereinstieg' => $this->progress->resumeHint($billingRun),
        ]);
    }

    /**
     * Fortschritt als JSON fuer die Aktualisierung ohne Seitenwechsel.
     */
    public function status(BillingRun $billingRun): JsonResponse
    {
        Gate::authorize('view', $billingRun);

        $progress = $this->reporter->report($billingRun);

        return response()->json([
            'unterlagen_gesamt' => $progress->documentsTotal,
            'unterlagen_geprueft' => $progress->documentsEvaluated,
            'unterlagen_fehlerhaft' => $progress->documentsFailed,
            'einheiten_erkannt' => $progress->unitsRecognized,
            'kostenpositionen' => $progress->costItemsAssigned,
            'offene_pruefungen' => $progress->openChecks,
            'blockierende_pruefungen' => $progress->blockingChecks,
            'prozent' => $progress->percent(),
            'abgeschlossen' => $progress->complete,
            'intervall_minuten' => self::schedulerIntervalMinutes(),
            'meldungen' => $progress->lines,
        ]);
    }

    /**
     * Startet die Zuordnung der ausgelesenen Inhaltsdaten zu
     * Kostenpositionen. Der Lauf bestaetigt nichts.
     */
    public function reconcile(BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $outcome = $this->reconcile->run($billingRun);

        $user = request()->user();

        if ($user instanceof User) {
            $this->statusmails->pruefaufgabenOffen($billingRun, $user, $outcome);
        }

        return redirect()
            ->route('portal.pruefung.kosten', ['billingRun' => $billingRun->getKey()])
            ->with('status', sprintf(
                '%d Unterlagen ausgewertet, %d Kostenpositionen vorgeschlagen. Bitte prüfen und bestätigen Sie '
                .'jede Position.',
                $outcome->documentsEvaluated,
                $outcome->proposalsCreated
            ));
    }

    /**
     * Tatsaechliche Aufloesung des Schedulers in Minuten (ADR-006).
     */
    public static function schedulerIntervalMinutes(): int
    {
        $wert = config('smartabrechnen.scheduler_interval_minutes');

        return max(1, is_numeric($wert) ? (int) $wert : 5);
    }
}
