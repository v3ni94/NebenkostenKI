<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Review;

use App\Application\Reconciliation\BillingModeAdvisor;
use App\Application\Reconciliation\SwitchBillingMode;
use App\Application\Wizard\PreviewBuilder;
use App\Application\Wizard\ReviewConfirmation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Review\SwitchBillingModeRequest;
use App\Models\BillingRun;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Abrechnungsweg waehlen (Abschnitt 5.3).
 *
 * Das System schlaegt den wahrscheinlich passenden Weg vor. Der Nutzer kann
 * wechseln. Ein Wechsel loescht keine ausgelesenen Inhaltsdaten; die
 * Positionen werden neu eingeordnet.
 */
class BillingModeController extends Controller
{
    public function __construct(
        private readonly BillingModeAdvisor $advisor,
        private readonly SwitchBillingMode $switch,
        private readonly PreviewBuilder $preview,
        private readonly ReviewConfirmation $confirmation,
    ) {}

    public function edit(BillingRun $billingRun): View
    {
        Gate::authorize('view', $billingRun);

        return view('portal.pruefung.weg', [
            'billingRun' => $billingRun,
            'vorschlag' => $this->advisor->suggest($billingRun),
        ]);
    }

    public function update(SwitchBillingModeRequest $request, BillingRun $billingRun): RedirectResponse
    {
        Gate::authorize('update', $billingRun);

        $modus = $request->modus();

        $this->switch->switch($billingRun, $modus);

        // Der Abrechnungsweg ordnet die Positionen neu ein und ist damit
        // abrechnungsrelevant (PreviewBuilder Regel 3).
        $this->preview->invalidate($billingRun);
        $this->confirmation->reset($billingRun);

        return redirect()
            ->route('portal.pruefung.weg.edit', ['billingRun' => $billingRun->getKey()])
            ->with('status', sprintf(
                'Der Abrechnungsweg ist jetzt "%s". Ihre ausgelesenen Inhaltsdaten bleiben erhalten und wurden '
                .'neu eingeordnet.',
                $modus->label()
            ));
    }
}
