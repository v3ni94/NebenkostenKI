<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Enums\BillingRunStatus;
use App\Enums\PaymentStatus;
use App\Models\BillingRun;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * Offene Faelle nach bestaetigter Zahlung (Abschnitt 15.1, 15.2).
 *
 * Leitgedanke: Ein Kunde, der bezahlt hat, muss seine Leistung erhalten, und
 * jeder Fehlerfall muss fuer den Betrieb sichtbar und wiederholbar sein. Diese
 * Klasse liest ausschliesslich und beantwortet drei Fragen:
 *
 *  1. Welche bezahlten Laeufe sind nicht finalisiert (FAILED oder haengend in
 *     PAID)? Sie sind ueber RetryFinalization nachzuholen.
 *  2. Welche bezahlten, finalisierten Laeufe haben keine Rechnung? Sie sind
 *     ueber IssueMissingInvoice nachzuholen, sobald die Betreiberstammdaten
 *     bestaetigt sind.
 *  3. Welche bestaetigten Zahlungen gehoeren zu keinem freischaltbaren Lauf
 *     (Zahlung ohne Lauf)? Sie sind kaufmaennisch zu klaeren.
 */
final class PaymentRecoveryOverview
{
    /**
     * Ein Lauf in PAID gilt erst nach dieser Zeit als haengend. Davor kann die
     * Finalisierung des Webhooks noch laufen.
     */
    public const int STUCK_PAID_MINUTES = 10;

    /**
     * Bezahlte Laeufe ohne Finalisierung.
     *
     * @return list<BillingRun>
     */
    public function unfinalizedPaidRuns(int $limit = 100): array
    {
        /** @var list<BillingRun> $runs */
        $runs = BillingRun::query()
            ->whereNotNull('paid_at')
            ->where(function ($query): void {
                $query
                    ->where('status', BillingRunStatus::FAILED->value)
                    ->orWhere(function ($stuck): void {
                        $stuck
                            ->where('status', BillingRunStatus::PAID->value)
                            ->where('paid_at', '<', Carbon::now()->subMinutes(self::STUCK_PAID_MINUTES));
                    });
            })
            ->orderBy('paid_at')
            ->limit($limit)
            ->get()
            ->all();

        return $runs;
    }

    /**
     * Finalisierte, bezahlte Laeufe ohne (nicht stornierte) Rechnung.
     *
     * @return list<BillingRun>
     */
    public function finalizedRunsWithoutInvoice(int $limit = 100): array
    {
        /** @var list<BillingRun> $runs */
        $runs = BillingRun::query()
            ->whereNotNull('paid_at')
            ->where('status', BillingRunStatus::FINALIZED->value)
            ->whereNotExists(function ($query): void {
                $query
                    ->from('invoices')
                    ->whereColumn('invoices.billing_run_id', 'billing_runs.id')
                    ->whereNull('invoices.cancels_invoice_id');
            })
            ->orderBy('finalized_at')
            ->limit($limit)
            ->get()
            ->all();

        return $runs;
    }

    /**
     * Bestaetigte Zahlungen, deren Lauf nicht freigeschaltet werden konnte.
     * Erkennbar am Fehlercode, den HandleStripeEvent auf der Zahlung setzt;
     * bei einer regulaeren Freischaltung wird er geleert. Dazu zaehlt auch eine
     * zweite Zahlung zu einem bereits bezahlten Lauf.
     *
     * @return list<Payment>
     */
    public function paymentsWithoutRun(int $limit = 100): array
    {
        /** @var list<Payment> $payments */
        $payments = Payment::query()
            ->with(['organization', 'user'])
            ->where('status', PaymentStatus::BEZAHLT->value)
            ->whereNotNull('failure_code')
            ->orderByDesc('paid_at')
            ->limit($limit)
            ->get()
            ->all();

        return $payments;
    }

    public function openCaseCount(): int
    {
        return count($this->unfinalizedPaidRuns())
            + count($this->finalizedRunsWithoutInvoice())
            + count($this->paymentsWithoutRun());
    }

    public function hasInvoice(BillingRun $billingRun): bool
    {
        return Invoice::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->whereNull('cancels_invoice_id')
            ->exists();
    }
}
