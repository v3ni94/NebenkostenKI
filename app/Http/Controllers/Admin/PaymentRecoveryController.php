<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\AdminAuditRecorder;
use App\Application\Payment\IssueMissingInvoice;
use App\Application\Payment\OperatorInvoiceBlocker;
use App\Application\Payment\PaymentRecoveryOverview;
use App\Application\Payment\RetryFinalization;
use App\Http\Controllers\Controller;
use App\Models\BillingRun;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Nachlauf nach bestaetigter Zahlung (Masterprompt 15, 20).
 *
 * Sichtbar werden drei offene Fallarten: bezahlte Laeufe ohne Finalisierung,
 * bezahlte Laeufe ohne Rechnung und Zahlungseingaenge ohne freischaltbaren
 * Lauf. Die ersten beiden sind hier per Handlung nachholbar; der dritte ist
 * eine kaufmaennische Entscheidung (Erstattung oder Zuordnung) und wird nur
 * angezeigt.
 *
 * Schreibende Handlungen sind ausschliesslich POST und werden mit Akteur
 * protokolliert. Es werden keine Dokumentinhalte angezeigt.
 */
final class PaymentRecoveryController extends Controller
{
    public function __construct(
        private readonly PaymentRecoveryOverview $overview,
        private readonly RetryFinalization $retry,
        private readonly IssueMissingInvoice $invoices,
        private readonly OperatorInvoiceBlocker $blocker,
        private readonly AdminAuditRecorder $audit,
    ) {}

    public function index(): View
    {
        return view('admin.zahlungsnachlauf', [
            'nicht_finalisiert' => $this->overview->unfinalizedPaidRuns(),
            'ohne_rechnung' => $this->overview->finalizedRunsWithoutInvoice(),
            'ohne_lauf' => $this->overview->paymentsWithoutRun(),
            'betreiber' => $this->blocker->state(),
        ]);
    }

    public function finalize(Request $request, BillingRun $billingRun): RedirectResponse
    {
        $nutzer = $this->actor($request);

        $this->audit->record(
            action: 'admin.billing_run.finalization_requested',
            actor: $nutzer,
            subject: $billingRun,
        );

        $fehler = $this->retry->one($billingRun, $nutzer);

        return redirect()
            ->route('admin.zahlungsnachlauf')
            ->with(
                $fehler === null ? 'status' : 'hinweis',
                $fehler === null
                    ? 'Die Finalisierung wurde nachgeholt. Die Dokumente stehen dem Kunden bereit.'
                    : 'Die Finalisierung ist erneut fehlgeschlagen: '.$fehler,
            );
    }

    public function issueInvoice(Request $request, BillingRun $billingRun): RedirectResponse
    {
        $nutzer = $this->actor($request);

        $this->audit->record(
            action: 'admin.invoice.late_issue_requested',
            actor: $nutzer,
            subject: $billingRun,
        );

        $fehler = $this->invoices->one($billingRun, $nutzer);

        return redirect()
            ->route('admin.zahlungsnachlauf')
            ->with(
                $fehler === null ? 'status' : 'hinweis',
                $fehler === null
                    ? 'Die Rechnung wurde nachgeholt und dem Kunden bereitgestellt.'
                    : 'Die Rechnung konnte nicht nachgeholt werden: '.$fehler,
            );
    }

    private function actor(Request $request): User
    {
        $nutzer = $request->user();

        if (! $nutzer instanceof User) {
            abort(403);
        }

        return $nutzer;
    }
}
