<?php

declare(strict_types=1);

namespace App\Application\Payment\Events;

use App\Models\BillingRun;
use App\Models\Invoice;
use App\Models\Payment;

/**
 * Der Abrechnungslauf ist finalisiert (Schritt 12).
 *
 * AUFRUFPUNKT DER BESTAETIGUNGS-E-MAIL: Das Ereignis wird genau einmal je Lauf
 * ausgeloest, nachdem
 *   - der Berechnungsstand gesperrt,
 *   - alle Final-PDFs wasserzeichenfrei neu erzeugt und mit SHA-256 gespeichert,
 *   - das ZIP-Paket bereitgestellt,
 *   - die Rechnung erzeugt oder als blockiert vermerkt und
 *   - der Lauf auf FINALIZED gesetzt wurde.
 *
 * Der Versand der Bestaetigungsmail mit sicherem Downloadlink gehoert NICHT zu
 * diesem Paket. Das E-Mail-Paket registriert einen Listener auf dieses
 * Ereignis. Es enthaelt bewusst keine PDF-Bytes und keine Mieterdaten, sondern
 * nur die Kennungen; der Listener laedt die erforderlichen Angaben selbst und
 * erzeugt den signierten Downloadlink ueber die vorhandene Route
 * portal.downloads.signed.
 */
final readonly class BillingRunFinalized
{
    /**
     * @param  list<string>  $generatedDocumentIds  erzeugte Final-Dokumente
     */
    public function __construct(
        public BillingRun $billingRun,
        public Payment $payment,
        public array $generatedDocumentIds,
        public ?string $packageDocumentId = null,
        public ?Invoice $invoice = null,
        public bool $invoiceBlocked = false,
    ) {}
}
