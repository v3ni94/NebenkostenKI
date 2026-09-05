<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Account\AuditRecorder;
use App\Application\Payment\Dto\PriceQuote;
use App\Application\Payment\Dto\VatDecomposition;
use App\Application\Payment\Exceptions\CustomerAddressMissingException;
use App\Application\Payment\Exceptions\OperatorMasterdataMissingException;
use App\Domain\Money\Money;
use App\Enums\InvoiceStatus;
use App\Models\BillingRun;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Services\Pdf\Renderer\OperatorInvoiceRenderer;
use App\Services\Pdf\Store\DocumentOwnership;
use App\Services\Pdf\Store\GeneratedDocumentWriter;
use App\Services\Pdf\View\InvoiceLine;
use App\Services\Pdf\View\InvoiceView;
use App\Services\Pdf\View\PostalAddress;
use App\Support\BusinessTimezone;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Use Case: Leistungsrechnung der Hausverwaltung Mueller GmbH an den Nutzer
 * (Abschnitt 15.2).
 *
 * VERBINDLICHE REGELN
 *
 *  1. Die Rechnungsnummer ist lueckenlos und wird atomar vergeben, siehe
 *     InvoiceNumberSequence.
 *  2. Eine festgeschriebene Rechnung wird NIEMALS ueberschrieben. Es gibt in
 *     dieser Klasse keine Methode, die eine bestehende Rechnung aendert. Eine
 *     Korrektur erfolgt ausschliesslich ueber cancel(): eine eigene
 *     Stornorechnung mit eigener Nummer, eigenem Beleg und Stornoreferenz.
 *  3. Netto, Umsatzsteuer und Brutto werden getrennt ausgewiesen. Die Summen
 *     stammen unveraendert aus dem serverseitig berechneten PriceQuote.
 *  4. Fehlen Steuer- oder Bankdaten des Betreibers oder sind die Stammdaten
 *     nicht bestaetigt, ist die produktive Erzeugung blockiert. Es wird dann
 *     KEINE Nummer verbraucht und keine Rechnung geschrieben. Fuer die Anzeige
 *     im Adminbereich liefert placeholderHtml() dieselbe Rechnung mit dem
 *     sichtbaren Platzhalter.
 *  5. Steuer- und Bankdaten stammen ausschliesslich aus der bestaetigten
 *     Konfiguration. Sie werden nie erfunden und nie aus Kundendaten abgeleitet.
 *  6. Ohne vollstaendige Rechnungsanschrift des Kunden wird keine Rechnung
 *     erzeugt und keine Nummer verbraucht (CustomerAddressMissingException).
 *     Der Fall erscheint im Zahlungsnachlauf und wird nach Ergaenzung der
 *     Anschrift nachgeholt.
 */
final class IssueOperatorInvoice
{
    public function __construct(
        private readonly InvoiceNumberSequence $numbers,
        private readonly OperatorInvoiceRenderer $renderer,
        private readonly GeneratedDocumentWriter $writer,
        private readonly OperatorInvoiceBlocker $blocker,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Erzeugt die Rechnung zu einer bestaetigten Zahlung.
     *
     * @throws OperatorMasterdataMissingException
     * @throws CustomerAddressMissingException
     */
    public function __invoke(
        BillingRun $billingRun,
        Payment $payment,
        PriceQuote $quote,
        ?User $actor = null,
    ): Invoice {
        $this->assertNotBlocked();

        $existing = $this->existingFor($payment);

        if ($existing instanceof Invoice) {
            // Idempotenz: eine bereits erzeugte Rechnung wird nicht ersetzt und
            // verbraucht keine zweite Nummer.
            return $existing;
        }

        $organization = $this->organizationOf($billingRun);
        $this->assertBillingAddress($organization);
        $today = BusinessTimezone::today();
        $description = $this->description($billingRun);

        $invoice = DB::transaction(function () use (
            $billingRun,
            $payment,
            $quote,
            $organization,
            $today,
            $description,
        ): Invoice {
            $number = $this->numbers->next((int) $today->format('Y'));

            /** @var Invoice $invoice */
            $invoice = Invoice::query()->create([
                'number' => $number,
                'organization_id' => $organization?->getKey(),
                'user_id' => $payment->getAttribute('user_id'),
                'billing_run_id' => $billingRun->getKey(),
                'payment_id' => $payment->getKey(),
                'customer_name' => $this->customerName($organization),
                'customer_address_line' => $organization?->getAttribute('billing_address_line'),
                'customer_address_extra' => $organization?->getAttribute('billing_address_extra'),
                'customer_postal_code' => $organization?->getAttribute('billing_postal_code'),
                'customer_city' => $organization?->getAttribute('billing_city'),
                'customer_country' => $this->customerCountry($organization),
                'customer_vat_id' => $organization?->getAttribute('vat_id'),
                'issued_on' => $today->format('Y-m-d'),
                'service_date' => $today->format('Y-m-d'),
                'net_cent' => $quote->netCent,
                'tax_cent' => $quote->taxCent,
                'gross_cent' => $quote->grossCent,
                'tax_rate_percent' => $quote->vatRatePercent,
                'currency' => $quote->currency,
                'status' => InvoiceStatus::BEZAHLT,
                'payment_method' => 'Stripe Checkout',
                'payment_reference' => $this->paymentReference($payment),
            ]);

            $this->writeItems($invoice, $quote, $description);

            return $invoice;
        });

        $this->attachPdf($invoice, $quote, $description, $organization);

        $this->audit->record(
            action: 'invoice.issued',
            subject: $invoice,
            actor: $actor,
            organization: $organization,
            metadata: [
                'nummer' => (string) $invoice->getAttribute('number'),
                'brutto_cent' => $quote->grossCent,
                'anzahl_abrechnungen' => $quote->statementCount,
            ],
        );

        return $invoice->refresh();
    }

    /**
     * Stornorechnung mit eigener Nummer, eigenem Beleg und Stornoreferenz.
     *
     * Die urspruengliche Rechnung bleibt inhaltlich unveraendert. Geaendert wird
     * ausschliesslich ihr Status auf STORNIERT; Nummer, Betraege, Anschrift und
     * das erzeugte PDF bleiben bestehen.
     *
     * @throws OperatorMasterdataMissingException
     */
    public function cancel(Invoice $original, string $reason, ?User $actor = null): Invoice
    {
        $this->assertNotBlocked();

        $existing = Invoice::query()
            ->where('cancels_invoice_id', $original->getKey())
            ->first();

        if ($existing instanceof Invoice) {
            return $existing;
        }

        $organizationId = $original->getAttribute('organization_id');
        $organization = is_string($organizationId)
            ? Organization::query()->find($organizationId)
            : null;

        $today = BusinessTimezone::today();
        $description = sprintf(
            'Storno zur Rechnung %s',
            (string) $original->getAttribute('number'),
        );

        $net = -1 * (int) $original->getAttribute('net_cent');
        $tax = -1 * (int) $original->getAttribute('tax_cent');
        $gross = -1 * (int) $original->getAttribute('gross_cent');

        $cancellation = DB::transaction(function () use (
            $original,
            $today,
            $description,
            $net,
            $tax,
            $gross,
        ): Invoice {
            $number = $this->numbers->next((int) $today->format('Y'));

            /** @var Invoice $cancellation */
            $cancellation = Invoice::query()->create([
                'number' => $number,
                'organization_id' => $original->getAttribute('organization_id'),
                'user_id' => $original->getAttribute('user_id'),
                'billing_run_id' => $original->getAttribute('billing_run_id'),
                'payment_id' => $original->getAttribute('payment_id'),
                'cancels_invoice_id' => $original->getKey(),
                'customer_name' => $original->getAttribute('customer_name'),
                'customer_address_line' => $original->getAttribute('customer_address_line'),
                'customer_address_extra' => $original->getAttribute('customer_address_extra'),
                'customer_postal_code' => $original->getAttribute('customer_postal_code'),
                'customer_city' => $original->getAttribute('customer_city'),
                'customer_country' => $original->getAttribute('customer_country'),
                'customer_vat_id' => $original->getAttribute('customer_vat_id'),
                'issued_on' => $today->format('Y-m-d'),
                'service_date' => $original->getAttribute('service_date'),
                'net_cent' => $net,
                'tax_cent' => $tax,
                'gross_cent' => $gross,
                'tax_rate_percent' => $original->getAttribute('tax_rate_percent'),
                'currency' => $original->getAttribute('currency'),
                'status' => InvoiceStatus::STORNORECHNUNG,
                'payment_method' => $original->getAttribute('payment_method'),
                'payment_reference' => $original->getAttribute('payment_reference'),
            ]);

            InvoiceItem::query()->create([
                'invoice_id' => $cancellation->getKey(),
                'position' => 1,
                'description' => $description,
                'quantity' => '1.0000',
                'unit_price_net_cent' => $net,
                'net_cent' => $net,
                'tax_rate_percent' => $original->getAttribute('tax_rate_percent'),
                'tax_cent' => $tax,
                'gross_cent' => $gross,
            ]);

            // Nur der Status der Ursprungsrechnung wird gesetzt. Ihre
            // Rechnungsangaben und ihr Beleg bleiben unveraendert.
            $original->forceFill(['status' => InvoiceStatus::STORNIERT])->save();

            return $cancellation;
        });

        $this->attachCancellationPdf($cancellation, $original, $description, $organization);

        $this->audit->record(
            action: 'invoice.cancelled',
            subject: $cancellation,
            actor: $actor,
            organization: $organization,
            metadata: [
                'nummer' => (string) $cancellation->getAttribute('number'),
                'storniert' => (string) $original->getAttribute('number'),
            ],
            reason: $reason,
        );

        return $cancellation->refresh();
    }

    /**
     * Rechnung mit sichtbarem Platzhalter, ohne Nummernvergabe und ohne
     * Persistenz. Dient der Anzeige im Adminbereich, solange Pflichtangaben
     * fehlen (Abschnitt 15.2).
     */
    public function placeholderHtml(BillingRun $billingRun, PriceQuote $quote): string
    {
        $organization = $this->organizationOf($billingRun);
        $today = BusinessTimezone::today();

        return $this->renderer->html($this->view(
            $this->numbers->format($this->previewPrefix(), (int) $today->format('Y'), 0),
            $today,
            $today,
            $quote,
            $this->description($billingRun),
            $organization,
            null,
        ));
    }

    /**
     * @throws OperatorMasterdataMissingException
     */
    private function assertNotBlocked(): void
    {
        if ($this->blocker->isBlocked()) {
            throw OperatorMasterdataMissingException::forFields(
                $this->blocker->missingFields(),
                $this->blocker->masterdataConfirmed(),
            );
        }
    }

    /**
     * Eine Rechnung ohne Anschrift des Kunden wird nicht festgeschrieben. Der
     * Checkout verlangt die Anschrift, der Kunde kann sie danach im Konto
     * leeren; eine nachgeholte Rechnung waere sonst ohne Anschrift. Die
     * Pruefung entspricht StartCheckout::hasBillingAddress.
     *
     * @throws CustomerAddressMissingException
     */
    private function assertBillingAddress(?Organization $organization): void
    {
        foreach (['billing_address_line', 'billing_postal_code', 'billing_city'] as $field) {
            if ($this->stringOrNull($organization?->getAttribute($field)) === null) {
                throw CustomerAddressMissingException::forBillingRun();
            }
        }
    }

    private function writeItems(Invoice $invoice, PriceQuote $quote, string $description): void
    {
        $unitNet = $quote->unitNetCent();
        $lineNet = $quote->netCent - $quote->baseNetCent();
        $lineGross = $quote->grossCent - $quote->baseGrossCent;

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->getKey(),
            'position' => 1,
            'description' => $description,
            'quantity' => sprintf('%d.0000', $quote->statementCount),
            'unit_price_net_cent' => $unitNet,
            'net_cent' => $lineNet,
            'tax_rate_percent' => $quote->vatRatePercent,
            'tax_cent' => $lineGross - $lineNet,
            'gross_cent' => $lineGross,
        ]);

        if (! $quote->hasBaseAmount()) {
            return;
        }

        $baseNet = $quote->baseNetCent();

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->getKey(),
            'position' => 2,
            'description' => 'Grundpreis je Abrechnungslauf',
            'quantity' => '1.0000',
            'unit_price_net_cent' => $baseNet,
            'net_cent' => $baseNet,
            'tax_rate_percent' => $quote->vatRatePercent,
            'tax_cent' => $quote->baseGrossCent - $baseNet,
            'gross_cent' => $quote->baseGrossCent,
        ]);
    }

    private function attachPdf(
        Invoice $invoice,
        PriceQuote $quote,
        string $description,
        ?Organization $organization,
    ): void {
        $issuedOn = new DateTimeImmutable((string) $invoice->getAttribute('issued_on'));

        $document = $this->renderer->render($this->view(
            (string) $invoice->getAttribute('number'),
            $issuedOn,
            $issuedOn,
            $quote,
            $description,
            $organization,
            $this->paymentReferenceOf($invoice),
        ));

        $organizationId = $invoice->getAttribute('organization_id');

        if (! is_string($organizationId) || $organizationId === '') {
            return;
        }

        $stored = $this->writer->store($document, new DocumentOwnership(
            $organizationId,
            is_string($invoice->getAttribute('billing_run_id'))
                ? (string) $invoice->getAttribute('billing_run_id')
                : null,
            null,
            (string) $invoice->getKey(),
        ));

        $invoice->forceFill(['pdf_sha256' => $stored->artifact->sha256])->save();
    }

    private function attachCancellationPdf(
        Invoice $cancellation,
        Invoice $original,
        string $description,
        ?Organization $organization,
    ): void {
        $issuedOn = new DateTimeImmutable((string) $cancellation->getAttribute('issued_on'));
        $gross = (int) $cancellation->getAttribute('gross_cent');
        $net = (int) $cancellation->getAttribute('net_cent');
        $tax = (int) $cancellation->getAttribute('tax_cent');

        $view = new InvoiceView(
            (string) $cancellation->getAttribute('number'),
            $issuedOn,
            $issuedOn,
            $this->customerAddress($organization, $cancellation),
            [new InvoiceLine($description, 1, Money::fromCents($net), Money::fromCents($net), 'Storno')],
            Money::fromCents($net),
            Money::fromCents($tax),
            Money::fromCents($gross),
            (string) $cancellation->getAttribute('tax_rate_percent'),
            'Stripe Checkout',
            $this->paymentReferenceOf($cancellation),
            is_string($cancellation->getAttribute('customer_vat_id'))
                ? (string) $cancellation->getAttribute('customer_vat_id')
                : null,
            (string) $original->getAttribute('number'),
        );

        $organizationId = $cancellation->getAttribute('organization_id');

        if (! is_string($organizationId) || $organizationId === '') {
            return;
        }

        $stored = $this->writer->store(
            $this->renderer->render($view),
            new DocumentOwnership(
                $organizationId,
                is_string($cancellation->getAttribute('billing_run_id'))
                    ? (string) $cancellation->getAttribute('billing_run_id')
                    : null,
                null,
                (string) $cancellation->getKey(),
            ),
        );

        $cancellation->forceFill(['pdf_sha256' => $stored->artifact->sha256])->save();
    }

    private function view(
        string $number,
        DateTimeImmutable $issuedOn,
        DateTimeImmutable $serviceDate,
        PriceQuote $quote,
        string $description,
        ?Organization $organization,
        ?string $paymentReference,
    ): InvoiceView {
        $lines = [new InvoiceLine(
            $description,
            $quote->statementCount,
            Money::fromCents($quote->unitNetCent()),
            Money::fromCents($quote->netCent - $quote->baseNetCent()),
        )];

        if ($quote->hasBaseAmount()) {
            $baseNet = $quote->baseNetCent();

            $lines[] = new InvoiceLine(
                'Grundpreis je Abrechnungslauf',
                1,
                Money::fromCents($baseNet),
                Money::fromCents($baseNet),
                'Abrechnungslauf',
            );
        }

        $vatId = $organization?->getAttribute('vat_id');

        return new InvoiceView(
            $number,
            $issuedOn,
            $serviceDate,
            $this->customerAddress($organization, null),
            $lines,
            $quote->net(),
            $quote->tax(),
            $quote->gross(),
            $this->formatRate($quote->vatRatePercent),
            'Stripe Checkout',
            $paymentReference,
            is_string($vatId) && $vatId !== '' ? $vatId : null,
        );
    }

    private function customerAddress(?Organization $organization, ?Invoice $invoice): PostalAddress
    {
        if ($invoice instanceof Invoice) {
            return new PostalAddress(
                (string) $invoice->getAttribute('customer_name'),
                $this->stringOrNull($invoice->getAttribute('customer_address_extra')),
                $this->stringOrNull($invoice->getAttribute('customer_address_line')),
                $this->stringOrNull($invoice->getAttribute('customer_postal_code')),
                $this->stringOrNull($invoice->getAttribute('customer_city')),
            );
        }

        return new PostalAddress(
            $this->customerName($organization),
            $this->stringOrNull($organization?->getAttribute('billing_address_extra')),
            $this->stringOrNull($organization?->getAttribute('billing_address_line')),
            $this->stringOrNull($organization?->getAttribute('billing_postal_code')),
            $this->stringOrNull($organization?->getAttribute('billing_city')),
        );
    }

    private function customerName(?Organization $organization): string
    {
        $billingName = $this->stringOrNull($organization?->getAttribute('billing_name'));

        if ($billingName !== null) {
            return $billingName;
        }

        return $this->stringOrNull($organization?->getAttribute('name')) ?? 'Kundenkonto';
    }

    private function customerCountry(?Organization $organization): string
    {
        $country = $this->stringOrNull($organization?->getAttribute('billing_country'));

        return $country ?? 'DE';
    }

    private function description(BillingRun $billingRun): string
    {
        $property = $billingRun->getAttribute('property');
        $label = is_object($property) && isset($property->label) && is_string($property->label)
            ? $property->label
            : 'Objekt';

        $start = $billingRun->getAttribute('period_start');
        $end = $billingRun->getAttribute('period_end');

        $period = is_object($start) && method_exists($start, 'format') && is_object($end) && method_exists($end, 'format')
            ? sprintf('%s bis %s', $start->format('d.m.Y'), $end->format('d.m.Y'))
            : (string) $billingRun->getAttribute('billing_year');

        return sprintf('Erstellung Betriebskostenabrechnung %s, %s', $label, $period);
    }

    private function existingFor(Payment $payment): ?Invoice
    {
        return Invoice::query()
            ->where('payment_id', $payment->getKey())
            ->whereNull('cancels_invoice_id')
            ->first();
    }

    private function organizationOf(BillingRun $billingRun): ?Organization
    {
        $id = $billingRun->getAttribute('organization_id');

        return is_string($id) ? Organization::query()->find($id) : null;
    }

    private function paymentReference(Payment $payment): ?string
    {
        return $this->stringOrNull($payment->getAttribute('payment_intent_id'))
            ?? $this->stringOrNull($payment->getAttribute('checkout_session_id'));
    }

    private function paymentReferenceOf(Invoice $invoice): ?string
    {
        return $this->stringOrNull($invoice->getAttribute('payment_reference'));
    }

    private function formatRate(string $rate): string
    {
        $normalized = VatDecomposition::fromGross(0, $rate)->ratePercent;

        return $normalized;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function previewPrefix(): string
    {
        $value = config('smartabrechnen.invoicing.number_prefix');

        return is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : 'NK';
    }
}
