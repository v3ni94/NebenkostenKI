<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Application\Payment\Dto\PriceQuote;
use App\Models\BillingRun;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payment\Dto\CheckoutSessionPayload;

/**
 * Baut die Angaben der gehosteten Zahlungsseite (Abschnitt 15.1).
 *
 * DATENSPARSAMKEIT, verbindlich: Die Leistungsbezeichnung ist neutral. Sie
 * nennt die Leistung, das Abrechnungsjahr und die Anzahl der Abrechnungen. Sie
 * nennt AUSDRUECKLICH NICHT
 *   - Mieternamen oder Mieteranschriften,
 *   - Objektanschriften oder Einheitenbezeichnungen,
 *   - Belegangaben, Betraege aus Kundendokumenten oder Dateiinhalte.
 *
 * Die Metadaten enthalten ausschliesslich technische Kennungen (ULIDs). Sie
 * binden die Sitzung an Abrechnungslauf, Nutzer, Mandant und Zahlung, damit der
 * spaetere Webhook eindeutig zuordnen kann.
 *
 * Beträge stammen ausschliesslich aus dem serverseitig berechneten PriceQuote.
 */
final class CheckoutSessionFactory
{
    public function build(
        BillingRun $billingRun,
        Payment $payment,
        PriceQuote $quote,
        ?User $user,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSessionPayload {
        $billingRunId = (string) $billingRun->getKey();
        $paymentId = (string) $payment->getKey();

        $metadata = [
            'billing_run_id' => $billingRunId,
            'payment_id' => $paymentId,
            'organization_id' => (string) $billingRun->getAttribute('organization_id'),
            'statement_count' => (string) $quote->statementCount,
        ];

        if ($user instanceof User) {
            $metadata['user_id'] = (string) $user->getKey();
        }

        return new CheckoutSessionPayload(
            $this->productName($billingRun, $quote),
            $quote->statementCount,
            $quote->unitGrossCent,
            $quote->baseGrossCent,
            $quote->currency,
            $billingRunId,
            $metadata,
            $successUrl,
            $cancelUrl,
            (string) $payment->getAttribute('idempotency_key'),
            $this->customerEmail($user),
            'Grundpreis je Abrechnungslauf',
        );
    }

    /**
     * Neutrale Leistungsbezeichnung.
     */
    public function productName(BillingRun $billingRun, PriceQuote $quote): string
    {
        $year = $billingRun->getAttribute('billing_year');
        $year = is_int($year) ? $year : (int) now()->format('Y');

        return sprintf(
            'Betriebskostenabrechnung %d, %d %s',
            $year,
            $quote->statementCount,
            $quote->statementCount === 1 ? 'Mieterabrechnung' : 'Mieterabrechnungen',
        );
    }

    /**
     * E-Mail-Adresse des Kontoinhabers fuer den Zahlungsbeleg des Anbieters.
     * Es wird keine Mieteradresse und keine Adresse aus einem Beleg verwendet.
     */
    private function customerEmail(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        $email = $user->getAttribute('email');

        return is_string($email) && $email !== '' ? $email : null;
    }
}
