<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Wizard;

use App\Application\Wizard\AllocationKeyWorkspace;
use App\Application\Wizard\PreviewBuilder;
use App\Application\Wizard\ReviewConfirmation;
use App\Application\Wizard\WizardProgress;
use App\Application\Wizard\WizardStep;
use App\Http\Requests\Wizard\StoreAllocationKeysRequest;
use App\Models\BillingRun;
use App\Models\Unit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Schritt 8 des geführten Ablaufs: Verteilerschlüssel und Verbrauch.
 *
 * Fehlende Werte und eine Anteilssumme ungleich 100 Prozent blockieren das
 * Weitergehen. Eine Abweichung von der Mietvertragsregelung warnt, blockiert
 * aber nicht.
 */
class AllocationKeyController extends WizardController
{
    public function __construct(
        WizardProgress $progress,
        private readonly AllocationKeyWorkspace $workspace,
        private readonly PreviewBuilder $preview,
        private readonly ReviewConfirmation $confirmation,
    ) {
        parent::__construct($progress);
    }

    public function show(BillingRun $billingRun): View
    {
        Gate::authorize('view', $billingRun);

        $this->progress->remember($billingRun, WizardStep::VERTEILERSCHLUESSEL);

        return view('portal.wizard.schluessel', array_merge(
            $this->frame($billingRun, WizardStep::VERTEILERSCHLUESSEL),
            [
                'zeilen' => $this->workspace->rows($billingRun),
                'blocker' => $this->workspace->blockingReasons($billingRun),
                'warnungen' => $this->workspace->warnings($billingRun),
                'schluesseltypen' => AllocationKeyWorkspace::selectableTypes(),
                'ersatzverteilung' => $this->workspace->unitsNeedingSubstituteConfirmation($billingRun),
            ]
        ));
    }

    public function store(StoreAllocationKeysRequest $request, BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $anzahl = $this->workspace->save($billingRun, $this->user(), $request->eingaben());

        $this->preview->invalidate($billingRun);
        $this->confirmation->reset($billingRun);

        return $this->back(
            $billingRun,
            WizardStep::VERTEILERSCHLUESSEL,
            sprintf(
                '%d Kostenarten sind gespeichert. Eine bestehende Vorschau wurde ungültig und wird neu erzeugt.',
                $anzahl
            )
        );
    }

    /**
     * Ausdrückliche Bestätigung einer Ersatzverteilung ohne Zwischenablesung.
     */
    public function confirmSubstitute(BillingRun $billingRun, string $unit): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $einheit = Unit::query()
            ->where('organization_id', $billingRun->getAttribute('organization_id'))
            ->where('property_id', $billingRun->getAttribute('property_id'))
            ->whereKey($unit)
            ->first();

        abort_unless($einheit instanceof Unit, 404);

        $this->workspace->confirmSubstituteDistribution($billingRun, (string) $einheit->getKey(), $this->user());

        $this->preview->invalidate($billingRun);
        $this->confirmation->reset($billingRun);

        return $this->back(
            $billingRun,
            WizardStep::VERTEILERSCHLUESSEL,
            sprintf(
                'Die Ersatzverteilung für %s ist bestätigt und wird in der Abrechnung gekennzeichnet.',
                $einheit->label
            )
        );
    }

    public function proceed(BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $blocker = $this->workspace->blockingReasons($billingRun);

        if ($blocker !== []) {
            return $this->withError($billingRun, WizardStep::VERTEILERSCHLUESSEL, 'weiter', $blocker[0]);
        }

        $this->progress->remember($billingRun, WizardStep::PRUEFBERICHT);

        return $this->back(
            $billingRun,
            WizardStep::PRUEFBERICHT,
            'Die Verteilerschlüssel sind vollständig. Wir haben die Prüfung gestartet.'
        );
    }
}
