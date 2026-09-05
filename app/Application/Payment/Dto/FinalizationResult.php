<?php

declare(strict_types=1);

namespace App\Application\Payment\Dto;

use App\Models\GeneratedDocument;
use App\Models\Invoice;

/**
 * Ergebnis der Finalisierung (Schritt 12).
 */
final readonly class FinalizationResult
{
    /**
     * @param  list<GeneratedDocument>  $documents  wasserzeichenfreie Einzel-PDFs
     * @param  list<string>  $invoiceBlockers  fehlende Pflichtangaben des Betreibers
     */
    public function __construct(
        public array $documents,
        public ?GeneratedDocument $package = null,
        public ?Invoice $invoice = null,
        public array $invoiceBlockers = [],
    ) {}

    public function invoiceIsBlocked(): bool
    {
        return $this->invoice === null;
    }

    public function documentCount(): int
    {
        return count($this->documents);
    }
}
