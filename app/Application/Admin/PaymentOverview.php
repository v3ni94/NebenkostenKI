<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Application\Payment\InvoiceNumberSequence;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * Zahlungen, Erstattungen, Rechnungen und Stornos (Masterprompt 15, 20).
 *
 * Die Klasse liest ausschliesslich. Ein Storno wird nicht hier ausgeloest,
 * sondern ueber App\Application\Payment\IssueOperatorInvoice::cancel(), damit
 * es genau einen Weg zur Stornorechnung gibt.
 */
final class PaymentOverview
{
    public function __construct(private readonly InvoiceNumberSequence $numbers) {}

    /**
     * @return list<Payment>
     */
    public function payments(int $limit = 50): array
    {
        /** @var list<Payment> $payments */
        $payments = Payment::query()
            ->with(['organization', 'user'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all();

        return $payments;
    }

    /**
     * @return list<Payment>
     */
    public function refunds(int $limit = 50): array
    {
        /** @var list<Payment> $payments */
        $payments = Payment::query()
            ->with(['organization', 'user'])
            ->whereNotNull('refunded_at')
            ->orderByDesc('refunded_at')
            ->limit($limit)
            ->get()
            ->all();

        return $payments;
    }

    /**
     * @return list<Invoice>
     */
    public function invoices(int $limit = 50): array
    {
        /** @var list<Invoice> $invoices */
        $invoices = Invoice::query()
            ->orderByDesc('issued_on')
            ->orderByDesc('number')
            ->limit($limit)
            ->get()
            ->all();

        return $invoices;
    }

    /**
     * @return list<Invoice>
     */
    public function cancellations(int $limit = 50): array
    {
        /** @var list<Invoice> $invoices */
        $invoices = Invoice::query()
            ->whereNotNull('cancels_invoice_id')
            ->orderByDesc('issued_on')
            ->limit($limit)
            ->get()
            ->all();

        return $invoices;
    }

    /**
     * @return array<string, int>
     */
    public function paymentStatusCounts(): array
    {
        $counts = [];

        foreach (PaymentStatus::cases() as $status) {
            $counts[$status->value] = Payment::query()->where('status', $status->value)->count();
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function invoiceStatusCounts(): array
    {
        $counts = [];

        foreach (InvoiceStatus::cases() as $status) {
            $counts[$status->value] = Invoice::query()->where('status', $status->value)->count();
        }

        return $counts;
    }

    /**
     * Rechnungsnummernkreis mit Lueckenpruefung.
     *
     * Eine Luecke ist nicht automatisch ein Fehler: eine vergebene Nummer wird
     * nie wiederverwendet, auch dann nicht, wenn die aufrufende Transaktion
     * spaeter scheitert. Die Pruefung macht die Luecke sichtbar und
     * dokumentierbar.
     *
     * @return array{
     *     jahr: int,
     *     letzter_wert: int,
     *     naechste_nummer: string,
     *     vergebene_rechnungen: int,
     *     fehlende_nummern: list<string>,
     *     lueckenlos: bool
     * }
     */
    public function numberRange(?int $year = null): array
    {
        $year ??= (int) Carbon::now()->format('Y');
        $prefix = $this->prefix();
        $last = $this->numbers->lastValue($year);

        /** @var list<string> $issued */
        $issued = Invoice::query()
            ->where('number', 'like', sprintf('%s-%04d-%%', $prefix, $year))
            ->orderBy('number')
            ->pluck('number')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();

        $missing = [];

        for ($value = 1; $value <= $last; $value++) {
            $number = $this->numbers->format($prefix, $year, $value);

            if (! in_array($number, $issued, true)) {
                $missing[] = $number;
            }
        }

        return [
            'jahr' => $year,
            'letzter_wert' => $last,
            'naechste_nummer' => $this->numbers->format($prefix, $year, $last + 1),
            'vergebene_rechnungen' => count($issued),
            'fehlende_nummern' => $missing,
            'lueckenlos' => $missing === [],
        ];
    }

    /**
     * Umsatz eines Zeitraums aus bezahlten Zahlungen, in Cent.
     */
    public function revenueCent(Carbon $from, Carbon $to): int
    {
        return (int) Payment::query()
            ->where('status', PaymentStatus::BEZAHLT->value)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount_cent');
    }

    public function refundedCent(Carbon $from, Carbon $to): int
    {
        return (int) Payment::query()
            ->whereNotNull('refunded_at')
            ->whereBetween('refunded_at', [$from, $to])
            ->sum('refunded_amount_cent');
    }

    private function prefix(): string
    {
        $value = config('smartabrechnen.invoicing.number_prefix');

        return is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : 'NK';
    }
}
