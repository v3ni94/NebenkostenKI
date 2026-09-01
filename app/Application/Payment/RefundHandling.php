<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Account\AuditRecorder;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\Payment;
use App\Services\Payment\Dto\VerifiedWebhookEvent;

/**
 * Erstattung und Rueckbelastung (Abschnitt 15.1).
 *
 * VERBINDLICHE REGELN
 *
 *  1. Eine Erstattung oder eine Rueckbelastung aendert einen finalisierten
 *     Abrechnungslauf NICHT. FINALIZED ist ein Endzustand, und ein bereits
 *     ausgelieferter Beleg wird nicht zurueckgezogen. Vermerkt wird der
 *     Zahlungsstatus, damit der kaufmaennische Vorgang nachvollziehbar bleibt.
 *  2. Eine Rechnung wird nicht ueberschrieben. Die kaufmaennische Korrektur
 *     erfolgt ausschliesslich ueber eine Stornorechnung, siehe
 *     IssueOperatorInvoice::cancel(). Sie wird bewusst NICHT automatisch aus
 *     einer Providermeldung heraus erzeugt: Ob und in welcher Hoehe storniert
 *     wird, ist eine kaufmaennische Entscheidung und bedarf der Freigabe durch
 *     die Geschaeftsfuehrung.
 *  3. Jeder Vorgang wird revisionssicher protokolliert und ist im Adminbereich
 *     als offener Punkt sichtbar.
 */
final class RefundHandling
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function refunded(Payment $payment, VerifiedWebhookEvent $event): WebhookProcessingStatus
    {
        $refunded = $event->refundedAmountCent() ?? 0;
        $paid = (int) $payment->getAttribute('amount_cent');

        $status = $refunded > 0 && $refunded < $paid
            ? PaymentStatus::TEILWEISE_ERSTATTET
            : PaymentStatus::ERSTATTET;

        $payment->forceFill([
            'status' => $status,
            'refunded_amount_cent' => $refunded,
            'refunded_at' => now(),
        ])->save();

        $this->audit->record(
            action: 'payment.refunded',
            subject: $payment,
            organization: is_string($payment->getAttribute('organization_id'))
                ? (string) $payment->getAttribute('organization_id')
                : null,
            metadata: [
                'erstattet_cent' => $refunded,
                'gezahlt_cent' => $paid,
                'status' => $status->value,
            ],
            reason: 'Erstattung des Zahlungsanbieters. Eine Stornorechnung ist kaufmännisch zu entscheiden '
                .'und durch die Geschäftsführung freizugeben.',
        );

        return WebhookProcessingStatus::VERARBEITET;
    }

    public function disputed(Payment $payment, VerifiedWebhookEvent $event): WebhookProcessingStatus
    {
        $payment->forceFill([
            'status' => PaymentStatus::ANGEFOCHTEN,
            'dispute_opened_at' => now(),
        ])->save();

        $this->audit->record(
            action: 'payment.disputed',
            subject: $payment,
            organization: is_string($payment->getAttribute('organization_id'))
                ? (string) $payment->getAttribute('organization_id')
                : null,
            metadata: [
                'betrag_cent' => $event->amountCent(),
                'zahlungsabsicht' => $event->paymentIntentId(),
            ],
            reason: 'Der Zahlungsvorgang wurde angefochten. Die Bearbeitung erfordert eine Entscheidung '
                .'der Geschäftsführung.',
        );

        return WebhookProcessingStatus::VERARBEITET;
    }
}
