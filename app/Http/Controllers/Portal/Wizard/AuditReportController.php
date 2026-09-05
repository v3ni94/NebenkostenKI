<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Wizard;

use App\Application\Wizard\AuditReportPresenter;
use App\Application\Wizard\WizardProgress;
use App\Application\Wizard\WizardStep;
use App\Enums\ValidationSeverity;
use App\Http\Requests\Wizard\DecideWarningRequest;
use App\Models\BillingRun;
use App\Models\ValidationIssue;
use App\Rules\Engine\RuleNotUserResolvableException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Schritt 9 des geführten Ablaufs: Prüfbericht.
 *
 * Blocker verhindern das Weitergehen zur Vorschau. Eine Warnung ist
 * ausschließlich mit ausdrücklicher, protokollierter Nutzerentscheidung
 * auflösbar. Nicht wegklickbare Hinweise, etwa im Heizkostenfall B ohne
 * vollständige Daten, bleiben stehen.
 */
class AuditReportController extends WizardController
{
    public function __construct(
        WizardProgress $progress,
        private readonly AuditReportPresenter $presenter,
    ) {
        parent::__construct($progress);
    }

    public function show(BillingRun $billingRun): View
    {
        Gate::authorize('view', $billingRun);

        $this->progress->remember($billingRun, WizardStep::PRUEFBERICHT);
        $bericht = $this->presenter->run($billingRun);

        return view('portal.wizard.pruefbericht', array_merge(
            $this->frame($billingRun, WizardStep::PRUEFBERICHT),
            [
                'gruppen' => $this->presenter->groups($billingRun),
                'anzahl' => $this->presenter->counts($billingRun),
                'regelstand' => $bericht->rulesetVersion,
                'weiterMoeglich' => $this->presenter->mayProceed($billingRun),
                'sperrgrund' => $this->presenter->blockingReason($billingRun),
                'offeneWarnungen' => $this->presenter->openWarnings($billingRun),
                'stufen' => ValidationSeverity::cases(),
            ]
        ));
    }

    public function decide(DecideWarningRequest $request, BillingRun $billingRun, string $issue): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $aufgabe = $this->presenter->issue($billingRun, $issue);

        abort_unless($aufgabe instanceof ValidationIssue, 404);

        try {
            $this->presenter->decide($aufgabe, $this->user(), $request->entscheidung());
        } catch (RuleNotUserResolvableException) {
            return $this->withError(
                $billingRun,
                WizardStep::PRUEFBERICHT,
                'entscheidung',
                'Dieses Prüfergebnis kann nicht durch eine Entscheidung aufgelöst werden. Bitte korrigieren Sie '
                .'die zugrunde liegenden Angaben.'
            );
        }

        return $this->back(
            $billingRun,
            WizardStep::PRUEFBERICHT,
            'Ihre Entscheidung ist protokolliert.'
        );
    }

    public function proceed(BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $grund = $this->presenter->blockingReason($billingRun);

        if ($grund !== null) {
            return $this->withError($billingRun, WizardStep::PRUEFBERICHT, 'weiter', $grund);
        }

        $this->progress->remember($billingRun, WizardStep::VORSCHAU);

        return $this->back(
            $billingRun,
            WizardStep::VORSCHAU,
            'Die Prüfung ist abgeschlossen. Bitte erzeugen Sie nun die Vorschau.'
        );
    }
}
