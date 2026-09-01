<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Wizard;

use App\Application\Wizard\PrepaymentWorkspace;
use App\Application\Wizard\PreviewBuilder;
use App\Application\Wizard\ReviewConfirmation;
use App\Application\Wizard\WizardProgress;
use App\Application\Wizard\WizardStep;
use App\Enums\ValueSource;
use App\Http\Requests\Wizard\StorePrepaymentsRequest;
use App\Models\BillingRun;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Schritt 7 des geführten Ablaufs: Vorauszahlungen.
 *
 * Pflichtschritt. Ohne erfasste oder ausdrücklich als Annahme bestätigte
 * Vorauszahlungen ist keine Abrechnung möglich.
 *
 * Das Speichern ist ein normaler POST-Aufruf mit Weiterleitung und
 * Statusmeldung. Der Ablauf ist damit jederzeit unterbrechbar.
 */
class PrepaymentController extends WizardController
{
    public function __construct(
        WizardProgress $progress,
        private readonly PrepaymentWorkspace $workspace,
        private readonly PreviewBuilder $preview,
        private readonly ReviewConfirmation $confirmation,
    ) {
        parent::__construct($progress);
    }

    public function show(BillingRun $billingRun): View
    {
        Gate::authorize('view', $billingRun);

        $this->progress->remember($billingRun, WizardStep::VORAUSZAHLUNGEN);

        return view('portal.wizard.vorauszahlungen', array_merge(
            $this->frame($billingRun, WizardStep::VORAUSZAHLUNGEN),
            [
                'zeilen' => $this->workspace->rows($billingRun),
                'offen' => $this->workspace->openReasons($billingRun),
                'herkuenfte' => ValueSource::cases(),
            ]
        ));
    }

    public function store(StorePrepaymentsRequest $request, BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $anzahl = $this->workspace->save($billingRun, $this->user(), $request->eingaben());

        // Die Vorauszahlungen sind abrechnungsrelevant. Eine bestehende
        // Vorschau und die Nutzerbestätigung verlieren damit ihre Grundlage.
        $this->preview->invalidate($billingRun);
        $this->confirmation->reset($billingRun);

        return $this->back(
            $billingRun,
            WizardStep::VORAUSZAHLUNGEN,
            sprintf(
                '%d Mietverhältnisse sind gespeichert. Eine bestehende Vorschau wurde ungültig und wird neu '
                .'erzeugt.',
                $anzahl
            )
        );
    }

    /**
     * Weiter zu Schritt 8. Offene Zeilen verhindern das Weitergehen.
     */
    public function proceed(BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $offen = $this->workspace->openReasons($billingRun);

        if ($offen !== []) {
            return $this->withError(
                $billingRun,
                WizardStep::VORAUSZAHLUNGEN,
                'weiter',
                $offen[0]
            );
        }

        $this->progress->remember($billingRun, WizardStep::VERTEILERSCHLUESSEL);

        return $this->back(
            $billingRun,
            WizardStep::VERTEILERSCHLUESSEL,
            'Die Vorauszahlungen sind vollständig. Bitte prüfen Sie nun die Verteilerschlüssel.'
        );
    }
}
