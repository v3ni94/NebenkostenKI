<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Review;

use App\Application\Heating\ManualHeatingConflictScanner;
use App\Application\Heating\ManualHeatingWorkspace;
use App\Application\Heating\StoreManualHeatingEntries;
use App\Domain\Calculation\Heating\ManualHeatingReconciler;
use App\Domain\Money\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Heating\StoreManualHeatingRequest;
use App\Models\BillingRun;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Manuelle Erfassung der Heizkosten je Einheit, Fall B (Abschnitt 12.3).
 *
 * Der Anwender traegt die von ihm selbst gerechneten Betraege ein. Die
 * Plattform uebernimmt sie unveraendert und prueft die Verteilung nach Grund-
 * und Verbrauchskosten sowie die CO2-Kostenaufteilung nicht.
 *
 * MANDANTENSCHUTZ: Jede Aktion prueft die Policy des Abrechnungslaufs. Eine
 * fremde Kennung fuehrt zu 403 oder 404 und verraet nichts ueber die Existenz
 * des Datensatzes.
 */
class ManualHeatingController extends Controller
{
    public function __construct(
        private readonly ManualHeatingWorkspace $workspace,
        private readonly ManualHeatingReconciler $reconciler,
        private readonly ManualHeatingConflictScanner $conflicts,
        private readonly StoreManualHeatingEntries $store,
    ) {}

    public function edit(BillingRun $billingRun): View
    {
        Gate::authorize('view', $billingRun);

        $input = $this->workspace->input($billingRun);
        $statement = $this->workspace->statement($billingRun);
        $decision = $statement?->getAttribute('manual_source_decision');

        return view('portal.pruefung.heizkosten-erfassung', [
            'billingRun' => $billingRun,
            'zeilen' => $this->workspace->rows($billingRun),
            'ergebnis' => $this->reconciler->reconcile($input, Money::fromCents(StoreManualHeatingEntries::toleranceCent())),
            'herkunft' => $input->calculationOrigin,
            'gesamtbetrag' => $input->declaredTotal instanceof Money ? $input->declaredTotal->formatAmount() : '',
            'konflikte' => $this->conflicts->conflictingSources($billingRun),
            'quelle' => is_string($decision) ? $decision : null,
        ]);
    }

    public function store(StoreManualHeatingRequest $request, BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $ergebnis = $this->store->handle(
            $billingRun,
            $this->user(),
            $request->amountsByUnit(),
            $request->declaredTotal(),
            $request->calculationOrigin(),
            $request->sourceDecision(),
        );

        $meldung = 'Die erfassten Heizkosten sind gespeichert und werden unverändert übernommen.';

        if ($ergebnis->blocksFinalization()) {
            $meldung .= ' Die Prüfsumme weicht über der Toleranz ab. Bitte klären oder korrigieren Sie die '
                .'Abweichung, bevor Sie die Abrechnung abschließen.';
        } elseif (! $ergebnis->checksumAvailable) {
            $meldung .= ' Ohne erfassten Gesamtbetrag ist keine Gegenprobe möglich.';
        }

        return redirect()
            ->route('portal.pruefung.heizkosten.erfassung', ['billingRun' => $billingRun->getKey()])
            ->with('status', $meldung);
    }

    private function user(): User
    {
        $user = request()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
