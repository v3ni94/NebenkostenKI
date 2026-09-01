<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Account\AuditRecorder;
use App\Application\BillingRun\BillingRunStateMachine;
use App\Application\BillingRun\IllegalStatusTransitionException;
use App\Enums\BillingRunStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Enums\WebhookSignatureStatus;
use App\Models\BillingRun;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\Payment\Dto\VerifiedWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Use Case: eine signaturgepruefte Providerbenachrichtigung verarbeiten
 * (Abschnitt 15.1, 23.3).
 *
 * VERBINDLICHE REGELN
 *
 *  1. Der Use Case nimmt ausschliesslich ein VerifiedWebhookEvent an. Eine
 *     ungeprueft eingelesene Nutzlast kann ihn nicht erreichen.
 *  2. Die Event-ID wird eindeutig gespeichert. Eine erneut zugestellte
 *     Benachrichtigung wird erkannt und nicht zweimal verarbeitet.
 *  3. Vor der Freischaltung werden Betrag, Waehrung und Abrechnungslauf
 *     verglichen. Bei einer Abweichung wird NICHT freigeschaltet; die
 *     Benachrichtigung wird als ignoriert mit Fehlercode vermerkt.
 *  4. Nur eine erfolgreiche, verglichene Zahlung setzt den Lauf auf PAID und
 *     loest die Finalisierung aus. Der Browser-Redirect erreicht diesen Weg
 *     nicht.
 *  5. Ein abgebrochener, fehlgeschlagener oder abgelaufener Vorgang laesst den
 *     Lauf im Vorschauzustand. Es entsteht dabei keine Rechnung.
 *  6. Die Nutzlast wird datensparsam und verschluesselt gespeichert. Roh-
 *     Payloads gelangen nicht in das Anwendungslog.
 */
final class HandleStripeEvent
{
    /**
     * Behandelte Ereignisarten.
     */
    public const string CHECKOUT_COMPLETED = 'checkout.session.completed';

    public const string CHECKOUT_ASYNC_SUCCEEDED = 'checkout.session.async_payment_succeeded';

    public const string CHECKOUT_ASYNC_FAILED = 'checkout.session.async_payment_failed';

    public const string CHECKOUT_EXPIRED = 'checkout.session.expired';

    public const string PAYMENT_FAILED = 'payment_intent.payment_failed';

    public const string PAYMENT_CANCELED = 'payment_intent.canceled';

    public const string CHARGE_REFUNDED = 'charge.refunded';

    public const string DISPUTE_CREATED = 'charge.dispute.created';

    public function __construct(
        private readonly BillingRunStateMachine $stateMachine,
        private readonly FinalizeBillingRun $finalize,
        private readonly RefundHandling $refunds,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return WebhookProcessingStatus Ergebnis der Verarbeitung
     */
    public function __invoke(VerifiedWebhookEvent $event): WebhookProcessingStatus
    {
        $record = $this->store($event);

        if ($record === null) {
            // Bereits vorhandene Event-ID: idempotent, nichts zu tun.
            return WebhookProcessingStatus::IGNORIERT;
        }

        try {
            $status = $this->dispatch($event, $record);
        } catch (Throwable $exception) {
            $this->fail($record, 'VERARBEITUNG_FEHLGESCHLAGEN', $exception->getMessage());

            throw $exception;
        }

        $record->forceFill([
            'processing_status' => $status,
            'processed_at' => now(),
        ])->save();

        return $status;
    }

    private function dispatch(VerifiedWebhookEvent $event, WebhookEvent $record): WebhookProcessingStatus
    {
        $payment = $this->findPayment($event);

        if (! $payment instanceof Payment) {
            $record->forceFill(['error_code' => 'ZAHLUNG_NICHT_GEFUNDEN'])->save();

            return WebhookProcessingStatus::IGNORIERT;
        }

        $record->forceFill(['payment_id' => $payment->getKey()])->save();

        return match ($event->eventType) {
            self::CHECKOUT_COMPLETED, self::CHECKOUT_ASYNC_SUCCEEDED => $this->succeed($event, $record, $payment),
            self::CHECKOUT_EXPIRED => $this->close($payment, PaymentStatus::ABGELAUFEN, 'ABGELAUFEN'),
            self::PAYMENT_FAILED, self::CHECKOUT_ASYNC_FAILED => $this->close(
                $payment,
                PaymentStatus::FEHLGESCHLAGEN,
                'FEHLGESCHLAGEN',
            ),
            self::PAYMENT_CANCELED => $this->close($payment, PaymentStatus::ABGEBROCHEN, 'ABGEBROCHEN'),
            self::CHARGE_REFUNDED => $this->refunds->refunded($payment, $event),
            self::DISPUTE_CREATED => $this->refunds->disputed($payment, $event),
            default => WebhookProcessingStatus::IGNORIERT,
        };
    }

    /**
     * Erfolgreiche Zahlung. Freigeschaltet wird erst nach dem Vergleich von
     * Betrag, Waehrung und Abrechnungslauf.
     */
    private function succeed(
        VerifiedWebhookEvent $event,
        WebhookEvent $record,
        Payment $payment,
    ): WebhookProcessingStatus {
        $billingRun = $this->billingRunOf($payment);

        if (! $billingRun instanceof BillingRun) {
            $record->forceFill(['error_code' => 'ABRECHNUNGSLAUF_NICHT_GEFUNDEN'])->save();

            return WebhookProcessingStatus::IGNORIERT;
        }

        $mismatch = $this->mismatch($event, $payment, $billingRun);

        if ($mismatch !== null) {
            // KEINE Freischaltung. Der Vorgang bleibt offen und wird im
            // Adminbereich als Abweichung sichtbar.
            $record->forceFill([
                'error_code' => $mismatch,
                'error_message' => 'Die Rückmeldung stimmt nicht mit dem serverseitig berechneten '
                    .'Zahlungsvorgang überein. Es wurde nichts freigeschaltet.',
            ])->save();

            $this->audit->record(
                action: 'payment.mismatch_rejected',
                subject: $payment,
                organization: is_string($billingRun->getAttribute('organization_id'))
                    ? (string) $billingRun->getAttribute('organization_id')
                    : null,
                metadata: [
                    'grund' => $mismatch,
                    'erwartet_cent' => (int) $payment->getAttribute('amount_cent'),
                    'gemeldet_cent' => $event->amountCent(),
                ],
            );

            return WebhookProcessingStatus::IGNORIERT;
        }

        if ($payment->getAttribute('status') === PaymentStatus::BEZAHLT) {
            // Idempotenz auf fachlicher Ebene: erneute Zustellung derselben
            // Zahlung aendert nichts.
            return WebhookProcessingStatus::VERARBEITET;
        }

        $payment->forceFill([
            'status' => PaymentStatus::BEZAHLT,
            'paid_at' => now(),
            'payment_intent_id' => $event->paymentIntentId() ?? $payment->getAttribute('payment_intent_id'),
        ])->save();

        $this->stateMachine->transitionTo($billingRun, BillingRunStatus::PAID, null, [
            'zahlung' => (string) $payment->getKey(),
            'brutto_cent' => (int) $payment->getAttribute('amount_cent'),
        ]);

        $this->finalizeQuietly($billingRun);

        return WebhookProcessingStatus::VERARBEITET;
    }

    /**
     * Die Finalisierung folgt unmittelbar auf die bestaetigte Zahlung. Ein
     * Fehler dabei darf die Verarbeitung der Benachrichtigung nicht scheitern
     * lassen: Die Zahlung ist bestaetigt, der Lauf geht auf FAILED und die
     * Finalisierung ist erneut ausloesbar.
     */
    private function finalizeQuietly(BillingRun $billingRun): void
    {
        try {
            ($this->finalize)($billingRun->refresh());
        } catch (Throwable $exception) {
            Log::warning('Die Finalisierung nach bestätigter Zahlung ist fehlgeschlagen.', [
                'abrechnungslauf' => (string) $billingRun->getKey(),
                'fehler' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Abgebrochener, fehlgeschlagener oder abgelaufener Vorgang. Der Lauf
     * kehrt in den Vorschauzustand zurueck.
     */
    private function close(Payment $payment, PaymentStatus $status, string $code): WebhookProcessingStatus
    {
        if ($payment->getAttribute('status') === PaymentStatus::BEZAHLT) {
            // Eine bestaetigte Zahlung wird durch eine spaetere Meldung nicht
            // entwertet. Erstattung und Rueckbelastung laufen ueber
            // RefundHandling.
            return WebhookProcessingStatus::IGNORIERT;
        }

        $attributes = ['status' => $status, 'failure_code' => $code];

        if ($status === PaymentStatus::ABGELAUFEN) {
            $attributes['expired_at'] = now();
        }

        $payment->forceFill($attributes)->save();

        $billingRun = $this->billingRunOf($payment);

        if ($billingRun instanceof BillingRun
            && $billingRun->getAttribute('status') === BillingRunStatus::CHECKOUT_PENDING) {
            try {
                $this->stateMachine->transitionTo($billingRun, BillingRunStatus::PREVIEW_READY, null, [
                    'grund' => $code,
                ]);
            } catch (IllegalStatusTransitionException) {
                // Der Lauf hat den Zustand inzwischen selbst verlassen.
            }
        }

        return WebhookProcessingStatus::VERARBEITET;
    }

    /**
     * Vergleich vor der Freischaltung. Rueckgabe ist der Fehlercode der
     * Abweichung oder null.
     */
    private function mismatch(VerifiedWebhookEvent $event, Payment $payment, BillingRun $billingRun): ?string
    {
        $expectedRun = (string) $payment->getAttribute('billing_run_id');
        $reportedRun = $event->metadata('billing_run_id') ?? $event->string('client_reference_id');

        if ($reportedRun !== null && $reportedRun !== $expectedRun) {
            return 'ABRECHNUNGSLAUF_ABWEICHEND';
        }

        $amount = $event->amountCent();

        if ($amount === null || $amount !== (int) $payment->getAttribute('amount_cent')) {
            return 'BETRAG_ABWEICHEND';
        }

        $expectedTotal = $billingRun->getAttribute('price_total_gross_cent');

        if (is_int($expectedTotal) && $expectedTotal !== $amount) {
            return 'BETRAG_ABWEICHEND';
        }

        $currency = $event->currency();

        if ($currency === null || $currency !== strtolower((string) $payment->getAttribute('currency'))) {
            return 'WAEHRUNG_ABWEICHEND';
        }

        $paymentStatus = $event->string('payment_status');

        if ($paymentStatus !== null && ! in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            return 'ZAHLUNGSSTATUS_OFFEN';
        }

        return null;
    }

    /**
     * Speichert die Benachrichtigung. Rueckgabe null bedeutet: die Event-ID war
     * bereits vorhanden, die Verarbeitung ist idempotent zu ueberspringen.
     */
    private function store(VerifiedWebhookEvent $event): ?WebhookEvent
    {
        try {
            /** @var WebhookEvent $record */
            $record = WebhookEvent::query()->create([
                'provider' => PaymentProvider::STRIPE,
                'provider_event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'signature_status' => WebhookSignatureStatus::GUELTIG,
                'processing_status' => WebhookProcessingStatus::EMPFANGEN,
                'payload_digest' => $event->payloadDigest,
                'payload' => $this->sparsePayload($event),
                'received_at' => now(),
                'attempts' => 1,
            ]);

            return $record;
        } catch (QueryException) {
            $existing = WebhookEvent::query()
                ->where('provider_event_id', $event->eventId)
                ->first();

            if ($existing instanceof WebhookEvent) {
                $existing->forceFill([
                    'attempts' => (int) $existing->getAttribute('attempts') + 1,
                ])->save();
            }

            return null;
        }
    }

    /**
     * Datensparsame Nutzlast: nur die technischen Felder, die fuer den
     * Nachweis und die Zuordnung erforderlich sind. Keine Kundenanschriften,
     * keine Belegdaten.
     */
    private function sparsePayload(VerifiedWebhookEvent $event): string
    {
        $payload = [
            'id' => $event->string('id'),
            'client_reference_id' => $event->string('client_reference_id'),
            'payment_intent' => $event->paymentIntentId(),
            'amount_cent' => $event->amountCent(),
            'currency' => $event->currency(),
            'payment_status' => $event->string('payment_status'),
            'billing_run_id' => $event->metadata('billing_run_id'),
            'payment_id' => $event->metadata('payment_id'),
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '{}' : $encoded;
    }

    private function findPayment(VerifiedWebhookEvent $event): ?Payment
    {
        $paymentId = $event->metadata('payment_id');

        if ($paymentId !== null) {
            $payment = Payment::query()->find($paymentId);

            if ($payment instanceof Payment) {
                return $payment;
            }
        }

        $sessionId = $event->checkoutSessionId();

        if ($sessionId !== null) {
            $payment = Payment::query()->where('checkout_session_id', $sessionId)->first();

            if ($payment instanceof Payment) {
                return $payment;
            }
        }

        $intentId = $event->paymentIntentId();

        if ($intentId !== null) {
            $payment = Payment::query()->where('payment_intent_id', $intentId)->first();

            if ($payment instanceof Payment) {
                return $payment;
            }
        }

        return null;
    }

    private function billingRunOf(Payment $payment): ?BillingRun
    {
        $id = $payment->getAttribute('billing_run_id');

        return is_string($id) ? BillingRun::query()->find($id) : null;
    }

    private function fail(WebhookEvent $record, string $code, string $message): void
    {
        $record->forceFill([
            'processing_status' => WebhookProcessingStatus::FEHLGESCHLAGEN,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 500),
            'processed_at' => now(),
        ])->save();
    }
}
