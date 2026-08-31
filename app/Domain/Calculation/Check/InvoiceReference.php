<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Check;

use App\Domain\Money\Money;

/**
 * Belegmerkmale einer Kostenposition für die Dublettenprüfung.
 *
 * Es werden ausschließlich strukturierte Extraktionsdaten verwendet
 * (Lieferant, Rechnungsnummer, Datum, Betrag, Dateifingerabdruck). Der
 * Fingerabdruck ist ein Hash und enthält keinen Dateiinhalt.
 */
final readonly class InvoiceReference
{
    public function __construct(
        public string $costItemKey,
        public string $label,
        public Money $amount,
        public ?string $supplier = null,
        public ?string $invoiceNumber = null,
        public ?string $invoiceDate = null,
        public ?string $fingerprint = null,
        public bool $isCreditNote = false,
        public ?string $relatedInvoiceCostItemKey = null,
    ) {}
}
