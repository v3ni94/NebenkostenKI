<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Review;

use App\Application\BillingRun\BillingRunProgress;
use App\Application\Reconciliation\CategoryResolver;
use App\Application\Review\BulkConfirmation;
use App\Application\Review\CostItemDecisions;
use App\Application\Review\CostReviewPresenter;
use App\Application\Review\ReviewGate;
use App\Application\Wizard\WizardProgress;
use App\Application\Wizard\WizardStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Review\AssignUnitRequest;
use App\Http\Requests\Review\BulkConfirmRequest;
use App\Http\Requests\Review\ExcludeCostItemRequest;
use App\Http\Requests\Review\StoreCostItemRequest;
use App\Http\Requests\Review\UpdateCostItemRequest;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Kostenpruefung, Schritt 6 des Ablaufs.
 *
 * DATENSCHUTZ: Es gibt bewusst keine Seitenansicht der Quelldokumente. Die
 * Originaldateien sind nach der Auswertung geloescht. Sichtbar sind nur die
 * neutrale Quellenbezeichnung, die Seite und der kurze gespeicherte
 * Fundstellenausschnitt.
 *
 * MANDANTENSCHUTZ: Jede Kostenposition wird ausschliesslich ueber eine auf den
 * Abrechnungslauf gescopte Query geladen. Eine fremde Kennung fuehrt zu 404.
 * Zusaetzlich entscheidet die Policy des Abrechnungslaufs.
 */
class CostReviewController extends Controller
{
    public function __construct(
        private readonly CostReviewPresenter $presenter,
        private readonly CostItemDecisions $decisions,
        private readonly BulkConfirmation $bulk,
        private readonly ReviewGate $gate,
        private readonly CategoryResolver $categories,
        private readonly BillingRunProgress $progress,
        private readonly WizardProgress $wizard,
    ) {}

    public function index(BillingRun $billingRun): View
    {
        Gate::authorize('view', $billingRun);

        $this->wizard->remember($billingRun, WizardStep::KOSTENPRUEFUNG);

        return view('portal.pruefung.kosten', [
            'billingRun' => $billingRun,
            'schritte' => $this->wizard->bar($billingRun, WizardStep::KOSTENPRUEFUNG),
            'wiedereinstieg' => $this->wizard->resumeHint($billingRun),
            'uebersicht' => $this->presenter->overview($billingRun),
            'kategorien' => $this->categories->selectable($billingRun),
            'einheiten' => $this->units($billingRun),
            'sperrgrund' => $this->gate->reason($billingRun),
            'weiterMoeglich' => $this->gate->mayProceed($billingRun),
        ]);
    }

    public function confirm(BillingRun $billingRun, string $costItem): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $item = $this->costItem($billingRun, $costItem);

        $this->decisions->confirm($billingRun, $item, $this->user());

        return $this->zurueck($billingRun, 'Die Position ist bestätigt.');
    }

    public function discard(BillingRun $billingRun, string $costItem): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $item = $this->costItem($billingRun, $costItem);

        $this->decisions->discard($billingRun, $item, $this->user());

        return $this->zurueck($billingRun, 'Die Position ist verworfen und geht nicht in die Abrechnung ein.');
    }

    public function exclude(ExcludeCostItemRequest $request, BillingRun $billingRun, string $costItem): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $item = $this->costItem($billingRun, $costItem);
        $grund = $request->input('grund');

        $this->decisions->exclude(
            $billingRun,
            $item,
            $this->user(),
            is_string($grund) && $grund !== '' ? $grund : null,
        );

        return $this->zurueck(
            $billingRun,
            'Die Position ist von der Umlage ausgeschlossen und wird getrennt ausgewiesen.'
        );
    }

    public function update(UpdateCostItemRequest $request, BillingRun $billingRun, string $costItem): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $item = $this->costItem($billingRun, $costItem);

        $this->decisions->update($billingRun, $item, $this->user(), $request->daten());

        return $this->zurueck($billingRun, 'Die Position ist gespeichert. Bitte bestätigen Sie sie anschließend.');
    }

    public function assign(AssignUnitRequest $request, BillingRun $billingRun, string $costItem): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $item = $this->costItem($billingRun, $costItem);
        $unitId = $request->input('unit_id');

        $this->decisions->assignToUnit(
            $billingRun,
            $item,
            $this->user(),
            is_string($unitId) && $unitId !== '' ? $unitId : null,
        );

        return $this->zurueck($billingRun, 'Die Zuordnung ist gespeichert.');
    }

    public function store(StoreCostItemRequest $request, BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $this->decisions->createManual($billingRun, $this->user(), $request->daten());

        return $this->zurueck(
            $billingRun,
            'Die Position ist erfasst. Sie ist noch nicht bestätigt.'
        );
    }

    public function bulkConfirm(BulkConfirmRequest $request, BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $ergebnis = $this->bulk->confirm($billingRun, $this->user(), $request->kennungen());

        $meldung = sprintf('%d Positionen sind bestätigt.', $ergebnis['confirmed']);

        if ($ergebnis['skipped'] > 0) {
            $meldung .= sprintf(
                ' %d Positionen wurden nicht bestätigt, weil sie einzeln zu prüfen sind, etwa wegen einer nicht '
                .'umlagefähigen Kostenart, eines Dublettenverdachts, einer Abweichung des Leistungszeitraums oder '
                .'einer geringen Erkennungssicherheit.',
                $ergebnis['skipped']
            );
        }

        return $this->zurueck($billingRun, $meldung);
    }

    /**
     * Weiter zum naechsten Schritt. Der Aufruf ist gesperrt, solange
     * Positionen offen sind oder Blocker bestehen.
     */
    public function proceed(BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $grund = $this->gate->reason($billingRun);

        if ($grund !== null) {
            $this->gate->recordBlockingIssue($billingRun);

            return redirect()
                ->route('portal.pruefung.kosten', ['billingRun' => $billingRun->getKey()])
                ->withErrors(['weiter' => $grund]);
        }

        // Erst hier ist jede Position entschieden und es besteht kein
        // Blocker mehr. Das ist der fachliche Abschluss der Kostenpruefung,
        // deshalb schaltet der Lauf auf READY_FOR_CALCULATION.
        $this->progress->bereitZurBerechnung($billingRun, $this->user());

        return redirect()
            ->route('portal.abrechnungen.show', ['billingRun' => $billingRun->getKey()])
            ->with('status', 'Die Kostenprüfung ist abgeschlossen.');
    }

    /**
     * @return list<Unit>
     */
    private function units(BillingRun $billingRun): array
    {
        $units = Unit::query()
            ->where('organization_id', $billingRun->getAttribute('organization_id'))
            ->where('property_id', $billingRun->getAttribute('property_id'))
            ->orderBy('label')
            ->get()
            ->all();

        return array_values($units);
    }

    private function costItem(BillingRun $billingRun, string $id): CostItem
    {
        $item = CostItem::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('organization_id', $billingRun->getAttribute('organization_id'))
            ->whereKey($id)
            ->first();

        abort_unless($item instanceof CostItem, 404);

        return $item;
    }

    private function user(): User
    {
        $user = request()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function zurueck(BillingRun $billingRun, string $meldung): RedirectResponse
    {
        return redirect()
            ->route('portal.pruefung.kosten', ['billingRun' => $billingRun->getKey()])
            ->with('status', $meldung);
    }
}
