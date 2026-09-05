<?php

declare(strict_types=1);

namespace App\Services\Pdf\View;

use App\Domain\Money\Money;
use DateTimeImmutable;

/**
 * Leistungsrechnung der Hausverwaltung Müller GmbH an den Nutzer
 * (Abschnitt 15.2).
 *
 * Die Rechnungsnummer wird als Parameter übergeben. Die lückenlose, atomare
 * Vergabe erfolgt außerhalb der PDF-Erzeugung.
 */
final readonly class InvoiceView
{
    /**
     * @param  list<InvoiceLine>  $lines
     */
    public function __construct(
        public string $number,
        public DateTimeImmutable $issuedOn,
        public DateTimeImmutable $serviceDate,
        public PostalAddress $customer,
        public array $lines,
        public Money $netTotal,
        public Money $taxTotal,
        public Money $grossTotal,
        public string $taxRatePercent,
        public string $paymentMethod,
        public ?string $paymentReference = null,
        public ?string $customerVatId = null,
        public ?string $cancelsInvoiceNumber = null,
    ) {}

    public function subjectLine(): string
    {
        return $this->cancelsInvoiceNumber !== null
            ? 'Stornorechnung '.$this->number
            : 'Rechnung '.$this->number;
    }

    public function isCancellation(): bool
    {
        return $this->cancelsInvoiceNumber !== null;
    }
}
