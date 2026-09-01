<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\PaymentOverview;
use App\Application\Payment\OperatorInvoiceBlocker;
use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Zahlungen, Erstattungen, Rechnungen, Stornos und Rechnungsnummernkreis
 * (Masterprompt 15, 20).
 */
final class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentOverview $overview,
        private readonly OperatorInvoiceBlocker $blocker,
    ) {}

    public function index(): View
    {
        $monatsbeginn = Carbon::now()->startOfMonth();

        return view('admin.zahlungen', [
            'zahlungen' => $this->overview->payments(),
            'erstattungen' => $this->overview->refunds(),
            'rechnungen' => $this->overview->invoices(),
            'stornos' => $this->overview->cancellations(),
            'zahlungsstatus' => $this->overview->paymentStatusCounts(),
            'rechnungsstatus' => $this->overview->invoiceStatusCounts(),
            'nummernkreis' => $this->overview->numberRange(),
            'umsatz_cent' => $this->overview->revenueCent($monatsbeginn, Carbon::now()),
            'betreiber' => $this->blocker->state(),
        ]);
    }
}
