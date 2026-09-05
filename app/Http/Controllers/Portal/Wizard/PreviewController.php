<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Wizard;

use App\Application\Calculation\CalculationInputException;
use App\Application\Calculation\EstimatePrice;
use App\Application\Review\ReviewGate;
use App\Application\Wizard\AllocationKeyWorkspace;
use App\Application\Wizard\AuditReportPresenter;
use App\Application\Wizard\PrepaymentWorkspace;
use App\Application\Wizard\PreviewBuilder;
use App\Application\Wizard\ReviewConfirmation;
use App\Application\Wizard\WizardProgress;
use App\Application\Wizard\WizardStep;
use App\Http\Requests\Wizard\ConfirmPreviewRequest;
use App\Listeners\SendProcessingStatusMails;
use App\Models\BillingRun;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Schritt 10 des geführten Ablaufs: Vorschau mit Wasserzeichen und
 * Nutzerbestätigung.
 *
 * VERBINDLICH
 *  - Alle Mieterabrechnungen und die Eigentümerübersicht werden serverseitig
 *    erzeugt und im Inline-Viewer durchscrollbar angezeigt.
 *  - Ein Download ist in dieser Phase ausschließlich mit Wasserzeichen
 *    möglich. Das Wasserzeichen ist ein wirksames Hemmnis, aber keine
 *    absolute Kopiersperre.
 *  - Vor dem Checkout bestätigt der Nutzer über eine nicht vorangekreuzte
 *    Checkbox. Die Bestätigung wird protokolliert.
 *  - Die Preisschätzung ist ausdrücklich unverbindlich. Der verbindliche
 *    Endpreis wird vor der Zahlung erneut berechnet.
 *  - Die Vorschau wird nur erzeugt, wenn die Pflichtschritte davor
 *    abgeschlossen sind: jede Kostenposition ist entschieden, die
 *    Vorauszahlungen sind erfasst, die Verteilerschlüssel sind vollständig und
 *    die Regel-Engine meldet keinen Blocker. Kein unbestätigter Wert gelangt in
 *    einen Berechnungsstand.
 */
class PreviewController extends WizardController
{
    public function __construct(
        WizardProgress $progress,
        private readonly PreviewBuilder $preview,
        private readonly EstimatePrice $prices,
        private readonly ReviewConfirmation $confirmation,
        private readonly AuditReportPresenter $report,
        private readonly ReviewGate $gate,
        private readonly PrepaymentWorkspace $prepayments,
        private readonly AllocationKeyWorkspace $keys,
        private readonly SendProcessingStatusMails $statusmails,
    ) {
        parent::__construct($progress);
    }

    public function show(BillingRun $billingRun): View
    {
        Gate::authorize('view', $billingRun);

        $this->progress->remember($billingRun, WizardStep::VORSCHAU);

        $dokumente = $this->preview->current($billingRun);

        return view('portal.wizard.vorschau', array_merge(
            $this->frame($billingRun, WizardStep::VORSCHAU),
            [
                'dokumente' => $dokumente,
                'gueltig' => $dokumente !== [],
                'schaetzung' => $this->prices->forBillingRun($billingRun),
                'bestaetigt' => $this->confirmation->isConfirmed($billingRun),
                'bestaetigungstext' => ReviewConfirmation::TEXT,
                'textversion' => ReviewConfirmation::TEXT_VERSION,
                'sperrgrund' => $this->sperrgrund($billingRun),
            ]
        ));
    }

    /**
     * Erzeugt die Vorschau neu. Offene Pflichtschritte und Blocker verhindern
     * die Erzeugung.
     */
    public function rebuild(BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('calculate', $billingRun);

        // Die Regel-Engine läuft vor der Prüfung, damit ein Blocker auch dann
        // sperrt, wenn der Prüfbericht nicht geöffnet wurde.
        $this->report->run($billingRun);

        $grund = $this->sperrgrund($billingRun);

        if ($grund !== null) {
            return $this->withError($billingRun, WizardStep::VORSCHAU, 'vorschau', $grund);
        }

        try {
            $dokumente = $this->preview->rebuild($billingRun, $this->user());
        } catch (CalculationInputException $exception) {
            return $this->withError($billingRun, WizardStep::VORSCHAU, 'vorschau', $exception->getMessage());
        }

        // Eine neue Vorschau entzieht einer früheren Bestätigung die
        // Grundlage. Der Protokolleintrag bleibt bestehen.
        $this->confirmation->reset($billingRun);

        $this->statusmails->vorschauBereit(
            $billingRun,
            $this->user(),
            $this->preview->statementCount($billingRun),
            $this->prices->forBillingRun($billingRun)->totalGross->cents,
        );

        return $this->back(
            $billingRun,
            WizardStep::VORSCHAU,
            sprintf(
                'Die Vorschau ist neu erzeugt. Sie umfasst %d Dokumente, jeweils mit Wasserzeichen.',
                count($dokumente)
            )
        );
    }

    /**
     * Nutzerbestätigung vor dem Checkout. Nach der Bestätigung geht es
     * unmittelbar zur Zahlung weiter.
     */
    public function confirm(ConfirmPreviewRequest $request, BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        if (! $this->preview->isValid($billingRun)) {
            return $this->withError(
                $billingRun,
                WizardStep::VORSCHAU,
                'bestaetigung',
                'Es liegt keine gültige Vorschau vor. Bitte erzeugen Sie die Vorschau zuerst.'
            );
        }

        // Ein Sperrgrund, der nach der Erzeugung der Vorschau entstanden ist,
        // verhindert die Bestätigung. Die Vorschau ist dann neu zu erzeugen.
        $grund = $this->sperrgrund($billingRun);

        if ($grund !== null) {
            return $this->withError($billingRun, WizardStep::VORSCHAU, 'bestaetigung', $grund);
        }

        $schaetzung = $this->prices->forBillingRun($billingRun);

        $this->confirmation->record(
            $billingRun,
            $this->user(),
            $this->preview->statementCount($billingRun),
            $schaetzung->totalGross->cents,
        );

        return redirect()
            ->route('portal.checkout.show', ['billingRun' => $billingRun->getKey()])
            ->with('status', 'Ihre Bestätigung ist protokolliert. Sie können nun zur Zahlung fortfahren.');
    }

    /**
     * Erster Grund, der die Erzeugung der Vorschau verhindert, oder null.
     *
     * Reihenfolge nach Ablauf: Kostenprüfung (Schritt 6), Vorauszahlungen
     * (Schritt 7), Verteilerschlüssel (Schritt 8), Prüfbericht (Schritt 9).
     */
    private function sperrgrund(BillingRun $billingRun): ?string
    {
        $grund = $this->gate->reason($billingRun);

        if ($grund !== null) {
            return $grund;
        }

        $offen = $this->prepayments->openReasons($billingRun);

        if ($offen !== []) {
            return 'Schritt 7 ist noch nicht abgeschlossen. '.$offen[0];
        }

        $blockiert = $this->keys->blockingReasons($billingRun);

        if ($blockiert !== []) {
            return 'Schritt 8 ist noch nicht abgeschlossen. '.$blockiert[0];
        }

        return $this->report->blockingReason($billingRun);
    }
}
