<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Review;

use App\Application\Reconciliation\HeatingReconciler;
use App\Application\Reconciliation\ReconcileBillingRun;
use App\Http\Controllers\Controller;
use App\Models\BillingRun;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Reconciliation-Matrix der Heizkosten (Abschnitt 7.4).
 *
 * Die Tabelle zeigt Quelle, Betrag, Einheit, Zeitraum und die vorgeschlagene
 * Behandlung. Sie rechnet nicht; die Betraege stammen aus den ausgelesenen
 * Inhaltsdaten und der Pruefsumme der Domain.
 */
class HeatingMatrixController extends Controller
{
    public function __construct(
        private readonly HeatingReconciler $heating,
        private readonly ReconcileBillingRun $reconcile,
    ) {}

    public function show(BillingRun $billingRun): View
    {
        Gate::authorize('view', $billingRun);

        return view('portal.pruefung.heizkosten', [
            'billingRun' => $billingRun,
            'matrix' => $this->heating->matrix($billingRun, $this->reconcile->documents($billingRun)),
        ]);
    }
}
