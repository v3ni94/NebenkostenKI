<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Account\AuditRecorder;
use App\Application\Payment\Dto\RecoveryReport;
use App\Application\Payment\Exceptions\OperatorMasterdataMissingException;
use App\Enums\BillingRunStatus;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\PaymentStatus;
use App\Mail\HvmRechnungVerfuegbarMail;
use App\Mail\MailDispatcher;
use App\Models\BillingRun;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Use Case: die Rechnung der Hausverwaltung Mueller GmbH fuer einen bezahlten,
 * bereits finalisierten Lauf nachholen (Abschnitt 15.2).
 *
 * VERBINDLICHE REGELN
 *
 *  1. Nachgeholt wird nur fuer Laeufe mit bestaetigter Zahlung und ohne
 *     (nicht stornierte) Rechnung. Ein Lauf mit Rechnung bleibt unangetastet.
 *  2. Die Rechnung stellt den TATSAECHLICH bezahlten Betrag
 *     (FinalizeBillingRun::paidQuote), nicht den heutigen Preis.
 *  3. Fehlen die Betreiberstammdaten weiterhin, entsteht keine Rechnung und
 *     keine Nummer wird verbraucht; der Fall bleibt offen und sichtbar.
 *  4. Der Rechnungsbeleg wird wie bei der Finalisierung als Final-Dokument
 *     zum Lauf abgelegt und erscheint damit im Abschlussbereich des Kunden.
 *     Die Rechnungsmail wird nachgesendet; ein Versandfehler hebt die erzeugte
 *     Rechnung nicht auf.
 */
final class IssueMissingInvoice
{
    public function __construct(
        private readonly IssueOperatorInvoice $invoices,
        private readonly FinalizeBillingRun $finalize,
        private readonly PaymentRecoveryOverview $overview,
        private readonly MailDispatcher $mailer,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return string|null Fehlermeldung oder null bei Erfolg
     */
    public function one(BillingRun $billingRun, ?User $actor = null): ?string
    {
        $payment = $this->confirmedPayment($billingRun);

        if (! $payment instanceof Payment || $billingRun->getAttribute('status') !== BillingRunStatus::FINALIZED) {
            return 'Für diesen Abrechnungslauf liegt keine bestätigte Zahlung mit abgeschlossener Finalisierung vor.';
        }

        if ($this->overview->hasInvoice($billingRun)) {
            return 'Zu diesem Abrechnungslauf liegt bereits eine Rechnung vor.';
        }

        $billingRun->loadMissing('property');

        try {
            $invoice = ($this->invoices)($billingRun, $payment, $this->finalize->paidQuote($payment), $actor);
        } catch (OperatorMasterdataMissingException $exception) {
            return $exception->getMessage();
        }

        $this->audit->record(
            action: 'invoice.issued_late',
            subject: $invoice,
            actor: $actor,
            organization: is_string($billingRun->getAttribute('organization_id'))
                ? (string) $billingRun->getAttribute('organization_id')
                : null,
            metadata: [
                'nummer' => (string) $invoice->getAttribute('number'),
                'abrechnungslauf' => (string) $billingRun->getKey(),
            ],
        );

        $this->sendInvoiceMail($billingRun, $payment, $invoice);

        return null;
    }

    public function all(int $limit = 25): RecoveryReport
    {
        $report = new RecoveryReport;

        foreach ($this->overview->finalizedRunsWithoutInvoice($limit) as $billingRun) {
            $error = $this->one($billingRun);

            if ($error === null) {
                $report->succeeded((string) $billingRun->getKey());
            } else {
                $report->failed((string) $billingRun->getKey(), $error);
            }
        }

        return $report;
    }

    private function confirmedPayment(BillingRun $billingRun): ?Payment
    {
        if ($billingRun->getAttribute('paid_at') === null) {
            return null;
        }

        $payment = Payment::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', PaymentStatus::BEZAHLT->value)
            ->orderByDesc('paid_at')
            ->first();

        return $payment instanceof Payment ? $payment : null;
    }

    /**
     * Nachgesendete Rechnungsmail mit dem Beleg als Anhang. Die Rechnung
     * enthaelt keine Mieterdaten und darf angehaengt werden (Masterprompt 16).
     */
    private function sendInvoiceMail(BillingRun $billingRun, Payment $payment, Invoice $invoice): void
    {
        $recipient = $this->recipient($billingRun, $payment);
        $address = $recipient?->getAttribute('email');

        if (! $recipient instanceof User || ! is_string($address) || trim($address) === '') {
            return;
        }

        $name = $recipient->getAttribute('name');
        $issuedOn = $invoice->getAttribute('issued_on');
        $organizationId = $billingRun->getAttribute('organization_id');

        $mail = new HvmRechnungVerfuegbarMail(
            anrede: is_string($name) && trim($name) !== '' ? 'Guten Tag '.trim($name).',' : 'Guten Tag,',
            rechnungsnummer: (string) $invoice->getAttribute('number'),
            bruttoCent: (int) $invoice->getAttribute('gross_cent'),
            ausgestelltAm: $issuedOn instanceof Carbon ? $issuedOn->format('d.m.Y') : Carbon::now()->format('d.m.Y'),
            portalUrl: route('portal.abschluss.show', ['billingRun' => $billingRun->getKey()]),
            rechnung: $this->invoiceDocument($invoice),
        );

        try {
            $this->mailer->send(
                mail: $mail,
                empfaenger: $address,
                nutzer: $recipient,
                organizationId: is_string($organizationId) ? $organizationId : null,
                lauf: $billingRun,
            );
        } catch (Throwable $exception) {
            Log::error('Die nachgesendete Rechnungsmail konnte nicht versendet werden.', [
                'abrechnungslauf' => (string) $billingRun->getKey(),
                'fehler' => $exception->getMessage(),
            ]);
        }
    }

    private function invoiceDocument(Invoice $invoice): ?GeneratedDocument
    {
        $document = GeneratedDocument::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('kind', GeneratedDocumentKind::HVM_RECHNUNG->value)
            ->where('variant', GeneratedDocumentVariant::FINAL->value)
            ->where('status', GeneratedDocumentStatus::AKTIV->value)
            ->orderByDesc('created_at')
            ->first();

        return $document instanceof GeneratedDocument ? $document : null;
    }

    private function recipient(BillingRun $billingRun, Payment $payment): ?User
    {
        foreach ([$payment->getAttribute('user_id'), $billingRun->getAttribute('created_by_user_id')] as $id) {
            $user = is_string($id) && $id !== '' ? User::query()->find($id) : null;

            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }
}
