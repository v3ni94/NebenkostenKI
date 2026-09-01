<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\AdminAuditRecorder;
use App\Application\Payment\Exceptions\OperatorMasterdataMissingException;
use App\Application\Payment\IssueOperatorInvoice;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InvoiceCancellationRequest;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Storno einer Leistungsrechnung (Masterprompt 15.2, 20).
 *
 * VERBINDLICH
 *
 *  1. Ein Storno ist ausschliesslich nach Freigabe durch die Geschaeftsfuehrung
 *     auszuloesen. Das Formular verlangt die ausdrueckliche Bestaetigung der
 *     Freigabe und eine Begruendung. Beides wird protokolliert.
 *  2. Es wird nichts ueberschrieben. Die Stornorechnung ist ein eigener Beleg
 *     mit eigener Nummer und Referenz auf die Ursprungsrechnung; die
 *     Ursprungsrechnung behaelt Nummer, Betraege, Anschrift und PDF und
 *     wechselt lediglich in den Status STORNIERT.
 *  3. Die Erzeugung selbst liegt unveraendert in
 *     App\Application\Payment\IssueOperatorInvoice::cancel(). Der Adminbereich
 *     bildet die Rechnungslogik nicht nach.
 */
final class InvoiceCancellationController extends Controller
{
    public function __construct(
        private readonly IssueOperatorInvoice $invoices,
        private readonly AdminAuditRecorder $audit,
    ) {}

    public function create(Invoice $invoice): View
    {
        return view('admin.rechnung-storno', [
            'rechnung' => $invoice,
            'bereits_storniert' => $this->existingCancellation($invoice) instanceof Invoice,
        ]);
    }

    public function store(InvoiceCancellationRequest $request, Invoice $invoice): RedirectResponse
    {
        $nutzer = $request->user();

        if (! $nutzer instanceof User) {
            abort(403);
        }

        if ($invoice->getAttribute('status') === InvoiceStatus::STORNORECHNUNG) {
            return redirect()
                ->route('admin.zahlungen')
                ->with('hinweis', 'Eine Stornorechnung kann nicht selbst storniert werden.');
        }

        $grund = $request->grund();

        // Die Freigabe und die Begruendung werden vor dem Vorgang protokolliert,
        // damit der Nachweis auch dann vorliegt, wenn die Erzeugung scheitert.
        $this->audit->record(
            action: 'admin.invoice.cancellation_requested',
            actor: $nutzer,
            subject: $invoice,
            metadata: [
                'rechnungsnummer' => (string) $invoice->getAttribute('number'),
                'freigabe_geschaeftsfuehrung' => true,
            ],
            reason: $grund,
        );

        try {
            $storno = $this->invoices->cancel($invoice, $grund, $nutzer);
        } catch (OperatorMasterdataMissingException $exception) {
            return redirect()
                ->route('admin.rechnungen.storno.create', $invoice)
                ->with('hinweis', $exception->getMessage());
        }

        return redirect()
            ->route('admin.zahlungen')
            ->with('status', sprintf(
                'Die Stornorechnung %s wurde erzeugt. Die Ursprungsrechnung %s bleibt unverändert erhalten.',
                (string) $storno->getAttribute('number'),
                (string) $invoice->getAttribute('number'),
            ));
    }

    private function existingCancellation(Invoice $invoice): ?Invoice
    {
        $existing = Invoice::query()
            ->where('cancels_invoice_id', $invoice->getKey())
            ->first();

        return $existing instanceof Invoice ? $existing : null;
    }
}
