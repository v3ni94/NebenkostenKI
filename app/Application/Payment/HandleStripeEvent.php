<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Account\AuditRecorder;
use App\Application\BillingRun\BillingRunStateMachine;
use App\Application\BillingRun\IllegalStatusTransitionException;
use App\Application\Payment\Exceptions\WebhookStillProcessingException;
use App\Application\Wizard\PreviewBuilder;
use App\Application\Wizard\ReviewConfirmation;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
 *     Benachrichtigung wird erkannt und nicht zweimal verarbeitet. Ist die
 *     erste Verarbeitung gescheitert, wird die Wiederzustellung erneut
 *     verarbeitet; erst ein abgeschlossener Datensatz gilt als Duplikat. Ein
 *     Datensatz in EMPFANGEN ohne processed_at ist nicht abgeschlossen und
 *     wird nie mit 200 quittiert (WebhookStillProcessingException).
 *  3. Vor der Freischaltung werden Betrag, Waehrung und Abrechnungslauf
 *     verglichen. Bei einer Abweichung wird NICHT freigeschaltet; die
 *     Benachrichtigung wird als ignoriert mit Fehlercode vermerkt.
 *  4. Nur eine erfolgreiche, verglichene Zahlung setzt den Lauf auf PAID und
 *     loest die Finalisierung aus. Der Browser-Redirect erreicht diesen Weg
 *     nicht. Zahlung und Statuswechsel des Laufs liegen in einer Transaktion,
 *     damit kein halber Zustand entsteht.
 *  5. Ein abgebrochener, fehlgeschlagener oder abgelaufener Vorgang laesst den
 *     Lauf im Vorschauzustand. Es entsteht dabei keine Rechnung.
 *  6. Ein Kunde, der bezahlt hat, erhaelt seine Leistung. Trifft eine
 *     bestaetigte Zahlung auf einen Lauf, der nicht mehr freigeschaltet werden
 *     kann, wird der Zahlungseingang festgehalten, protokolliert und im
 *     Adminbereich als offener Fall sichtbar gemacht. Er geht nicht verloren.
 *  7. Die Nutzlast wird datensparsam und verschluesselt gespeichert. Roh-
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

    /**
     * Fehlercode einer bestaetigten Zahlung, deren Lauf nicht mehr
     * freigeschaltet werden kann. Der Fall ist im Adminbereich zu klaeren.
     */
    public const string ZAHLUNG_OHNE_LAUF = 'ZAHLUNG_OHNE_LAUF';

    /**
     * Fehlercode einer bestaetigten Zahlung, deren Lauf keine gueltige und
     * vom Nutzer bestaetigte Vorschau mehr traegt. Der bezahlte Stand
     * entspricht nicht der bestaetigten Vorschau; es wird nicht finalisiert,
     * der Fall ist im Zahlungsnachlauf zu klaeren.
     */
    public const string VORSCHAU_UNGUELTIG = 'VORSCHAU_UNGUELTIG';

    /**
     * Nach dieser Zeit gilt ein noch als EMPFANGEN gespeicherter Datensatz als
     * liegen geblieben und wird bei erneuter Zustellung erneut verarbeitet.
     * Davor wird eine Wiederzustellung mit einer Ausnahme (Antwort 500)
     * beantwortet, nie mit 200: ein EMPFANGEN ohne processed_at ist kein
     * abgeschlossenes Ereignis.
     */
    public const int STALE_RECEIVED_MINUTES = 5;

    public function __construct(
        private readonly BillingRunStateMachine $stateMachine,
        private readonly FinalizeBillingRun $finalize,
        private readonly RefundHandling $refunds,
        private readonly AuditRecorder $audit,
        private readonly CalculatePrice $prices,
        private readonly PreviewBuilder $preview,
        private readonly ReviewConfirmation $confirmation,
    ) {}

    /**
     * @return WebhookProcessingStatus Ergebnis der Verarbeitung
     */
    public function __invoke(VerifiedWebhookEvent $event): WebhookProcessingStatus
    {
        $record = $this->store($event);

        if ($record === null) {
            // Bereits abschliessend verarbeitete Event-ID: idempotent, nichts zu tun.
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
            self::CHECKOUT_ASYNC_FAILED => $this->close($payment, PaymentStatus::FEHLGESCHLAGEN, 'FEHLGESCHLAGEN'),
            self::PAYMENT_FAILED => $this->noteFailedAttempt($payment, $record),
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
        $paymentStatus = $event->string('payment_status');

        if ($paymentStatus !== null && ! in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            // Regulaere Erstmeldung einer asynchronen Zahlart (etwa Lastschrift):
            // die Zahlung ist eingeleitet, die Bestaetigung folgt als eigenes
            // Ereignis. Das ist keine Abweichung und wird nicht als solche
            // protokolliert.
            $record->forceFill([
                'error_code' => 'ZAHLUNGSSTATUS_OFFEN',
                'error_message' => 'Die Zahlung ist eingeleitet, die Bestätigung des Zahlungsanbieters steht '
                    .'noch aus. Es wurde nichts freigeschaltet.',
            ])->save();

            return WebhookProcessingStatus::IGNORIERT;
        }

        $billingRun = $this->billingRunOf($payment);

        if (! $billingRun instanceof BillingRun) {
            // Der Lauf ist geloescht, das Geld aber eingegangen. Der Eingang
            // wird festgehalten und dem Betrieb als offener Fall gemeldet.
            return $this->recordPaymentWithoutRun($event, $record, $payment, null, 'ABRECHNUNGSLAUF_NICHT_GEFUNDEN');
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
            // Zahlung aendert nichts. Ist die Finalisierung nach der Zahlung
            // liegen geblieben, wird sie hier nachgeholt.
            $this->finalizeIfPending($billingRun);

            return WebhookProcessingStatus::VERARBEITET;
        }

        if (! $this->paymentMayBeConfirmed($payment)) {
            // Erstattete oder angefochtene Vorgaenge werden durch eine spaetere
            // Erfolgsmeldung nicht wieder bestaetigt.
            $record->forceFill(['error_code' => 'ZAHLUNGSSTATUS_ENDGUELTIG'])->save();

            return WebhookProcessingStatus::IGNORIERT;
        }

        $blocker = $this->releaseBlocker($billingRun, $payment);

        if ($blocker !== null) {
            return $this->recordPaymentWithoutRun($event, $record, $payment, $billingRun, $blocker);
        }

        // Zahlung und Statuswechsel des Laufs in EINER Transaktion: es gibt
        // keine bezahlte Zahlung ohne bezahlten Lauf und umgekehrt.
        DB::transaction(function () use ($event, $payment, $billingRun): void {
            $payment->forceFill([
                'status' => PaymentStatus::BEZAHLT,
                'paid_at' => now(),
                'failure_code' => null,
                'payment_intent_id' => $event->paymentIntentId() ?? $payment->getAttribute('payment_intent_id'),
            ])->save();

            if ($billingRun->getAttribute('status') === BillingRunStatus::PREVIEW_READY) {
                // Der Lauf hat den Checkout verlassen, waehrend die Sitzung
                // beim Anbieter noch erfolgreich werden konnte. Der erlaubte
                // Weg fuehrt ueber CHECKOUT_PENDING.
                $this->stateMachine->transitionTo($billingRun, BillingRunStatus::CHECKOUT_PENDING, null, [
                    'zahlung' => (string) $payment->getKey(),
                    'grund' => 'ZAHLUNGSEINGANG_NACH_ABBRUCH',
                ]);
            }

            $this->stateMachine->transitionTo($billingRun, BillingRunStatus::PAID, null, [
                'zahlung' => (string) $payment->getKey(),
                'brutto_cent' => (int) $payment->getAttribute('amount_cent'),
            ]);
        });

        $this->finalizeQuietly($billingRun);

        return WebhookProcessingStatus::VERARBEITET;
    }

    /**
     * Grund, warum der Lauf trotz bestaetigter Zahlung nicht freigeschaltet
     * werden kann, oder null.
     */
    private function releaseBlocker(BillingRun $billingRun, Payment $payment): ?string
    {
        $status = $billingRun->getAttribute('status');

        if (! in_array($status, [BillingRunStatus::CHECKOUT_PENDING, BillingRunStatus::PREVIEW_READY], true)) {
            return self::ZAHLUNG_OHNE_LAUF;
        }

        if ($billingRun->getAttribute('active_calculation_snapshot_id') === null) {
            return 'BERECHNUNGSSTAND_FEHLT';
        }

        // Zweite Verteidigungslinie neben PreviewInvalidator: Freigeschaltet
        // wird nur ein Stand mit gueltiger Vorschau und bestehender
        // Nutzerbestaetigung. Wurde die Vorschau nach dem Start des Checkouts
        // ungueltig (Stammdatenaenderung), passt die Zahlung nicht mehr zur
        // bestaetigten Leistung.
        if (! $this->preview->isValid($billingRun) || ! $this->confirmation->isConfirmed($billingRun)) {
            return self::VORSCHAU_UNGUELTIG;
        }

        // Der bezahlte Stand muss dem aktuellen Berechnungsstand entsprechen.
        // Wurde der Lauf nach dem Abbruch veraendert, passt die Zahlung nicht
        // mehr zur Leistung; der Fall geht an den Betrieb.
        if ($this->prices->statementCount($billingRun) !== (int) $payment->getAttribute('statement_count')) {
            return 'BERECHNUNGSSTAND_GEAENDERT';
        }

        return null;
    }

    /**
     * Ein Zahlungseingang, der keinem freischaltbaren Lauf zugeordnet werden
     * kann. Das Geld ist beim Anbieter vereinnahmt; der Eingang wird auf der
     * Zahlung festgehalten, revisionssicher protokolliert und im Adminbereich
     * als offener Fall gefuehrt. Der Lauf wird nicht angefasst.
     */
    private function recordPaymentWithoutRun(
        VerifiedWebhookEvent $event,
        WebhookEvent $record,
        Payment $payment,
        ?BillingRun $billingRun,
        string $code,
    ): WebhookProcessingStatus {
        if ($payment->getAttribute('status') !== PaymentStatus::BEZAHLT) {
            $payment->forceFill([
                'status' => PaymentStatus::BEZAHLT,
                'paid_at' => now(),
                'failure_code' => $code,
                'payment_intent_id' => $event->paymentIntentId() ?? $payment->getAttribute('payment_intent_id'),
            ])->save();
        }

        $record->forceFill([
            'error_code' => $code,
            'error_message' => 'Der Zahlungseingang ist bestätigt, der Abrechnungslauf kann aber nicht '
                .'freigeschaltet werden. Der Fall ist im Adminbereich zu klären.',
        ])->save();

        $status = $billingRun?->getAttribute('status');

        $this->audit->record(
            action: 'payment.received_without_run',
            subject: $payment,
            organization: is_string($payment->getAttribute('organization_id'))
                ? (string) $payment->getAttribute('organization_id')
                : null,
            metadata: [
                'grund' => $code,
                'abrechnungslauf' => (string) $payment->getAttribute('billing_run_id'),
                'laufstatus' => $status instanceof BillingRunStatus ? $status->value : null,
                'brutto_cent' => (int) $payment->getAttribute('amount_cent'),
            ],
            reason: 'Zahlungseingang ohne freischaltbaren Abrechnungslauf. Erstattung oder Zuordnung ist '
                .'kaufmännisch zu entscheiden und durch die Geschäftsführung freizugeben.',
        );

        Log::warning('Ein Zahlungseingang konnte keinem freischaltbaren Abrechnungslauf zugeordnet werden.', [
            'zahlung' => (string) $payment->getKey(),
            'grund' => $code,
        ]);

        return WebhookProcessingStatus::VERARBEITET;
    }

    private function paymentMayBeConfirmed(Payment $payment): bool
    {
        return in_array($payment->getAttribute('status'), [
            PaymentStatus::ERSTELLT,
            PaymentStatus::AUSSTEHEND,
            PaymentStatus::ABGEBROCHEN,
            PaymentStatus::ABGELAUFEN,
            PaymentStatus::FEHLGESCHLAGEN,
        ], true);
    }

    /**
     * Die Finalisierung folgt unmittelbar auf die bestaetigte Zahlung. Ein
     * Fehler dabei darf die Verarbeitung der Benachrichtigung nicht scheitern
     * lassen: Die Zahlung ist bestaetigt, der Lauf geht auf FAILED und die
     * Finalisierung ist ueber den Adminbereich, den Befehl
     * smartabrechnen:retry-finalization und den Zeitplan erneut ausloesbar.
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
     * Nachholen einer liegen gebliebenen Finalisierung bei erneuter
     * Erfolgsmeldung zu einer bereits bestaetigten Zahlung.
     */
    private function finalizeIfPending(BillingRun $billingRun): void
    {
        $status = $billingRun->getAttribute('status');

        if ($billingRun->getAttribute('paid_at') === null) {
            return;
        }

        if (in_array($status, [BillingRunStatus::PAID, BillingRunStatus::FAILED], true)) {
            $this->finalizeQuietly($billingRun);
        }
    }

    /**
     * Abgelehnter Zahlversuch innerhalb einer weiterhin offenen Sitzung. Der
     * Kunde kann in derselben Sitzung sofort erneut zahlen; der Vorgang wird
     * deshalb nur vermerkt und weder geschlossen noch der Lauf zurueckgesetzt.
     * Endgueltig sind ausschliesslich das Ablaufen der Sitzung und das
     * Scheitern einer asynchronen Zahlart.
     */
    private function noteFailedAttempt(Payment $payment, WebhookEvent $record): WebhookProcessingStatus
    {
        if ($payment->getAttribute('status') === PaymentStatus::BEZAHLT) {
            return WebhookProcessingStatus::IGNORIERT;
        }

        if (in_array($payment->getAttribute('status'), [PaymentStatus::ERSTELLT, PaymentStatus::AUSSTEHEND], true)) {
            $payment->forceFill(['failure_code' => 'ZAHLVERSUCH_FEHLGESCHLAGEN'])->save();
        }

        $record->forceFill(['error_code' => 'ZAHLVERSUCH_FEHLGESCHLAGEN'])->save();

        return WebhookProcessingStatus::VERARBEITET;
    }

    /**
     * Abgebrochener, fehlgeschlagener oder abgelaufener Vorgang. Der Lauf
     * kehrt in den Vorschauzustand zurueck, sofern kein anderer offener
     * Zahlungsvorgang zu ihm existiert.
     */
    private function close(Payment $payment, PaymentStatus $status, string $code): WebhookProcessingStatus
    {
        if ($payment->getAttribute('status') === PaymentStatus::BEZAHLT) {
            // Eine bestaetigte Zahlung wird durch eine spaetere Meldung nicht
            // entwertet. Erstattung und Rueckbelastung laufen ueber
            // RefundHandling.
            return WebhookProcessingStatus::IGNORIERT;
        }

        if (in_array($payment->getAttribute('status'), [PaymentStatus::ERSTELLT, PaymentStatus::AUSSTEHEND], true)) {
            $attributes = ['status' => $status, 'failure_code' => $code];

            if ($status === PaymentStatus::ABGELAUFEN) {
                $attributes['expired_at'] = now();
            }

            $payment->forceFill($attributes)->save();
        }

        $billingRun = $this->billingRunOf($payment);

        if ($billingRun instanceof BillingRun
            && $billingRun->getAttribute('status') === BillingRunStatus::CHECKOUT_PENDING
            && ! $this->hasOtherOpenPayment($billingRun, $payment)) {
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
     * Ein weiterer offener Zahlungsvorgang zum Lauf, etwa nach einer
     * Preisaenderung waehrend des Checkouts. Die verspaetete Meldung zur alten
     * Sitzung darf den laufenden Checkout dann nicht zuruecksetzen.
     */
    private function hasOtherOpenPayment(BillingRun $billingRun, Payment $payment): bool
    {
        return Payment::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->whereKeyNot($payment->getKey())
            ->whereIn('status', [PaymentStatus::ERSTELLT->value, PaymentStatus::AUSSTEHEND->value])
            ->exists();
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

        return null;
    }

    /**
     * Speichert die Benachrichtigung. Rueckgabe null bedeutet: die Event-ID war
     * bereits vorhanden und abschliessend verarbeitet, die Verarbeitung ist
     * idempotent zu ueberspringen.
     *
     * Ein vorhandener Datensatz mit gescheiterter oder liegen gebliebener
     * Verarbeitung wird zurueckgegeben und erneut verarbeitet: Der Anbieter
     * stellt nach einer Antwort 500 genau dafuer erneut zu.
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
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                // Jeder andere Datenbankfehler wird weitergeworfen. Der
                // Controller antwortet 500, der Anbieter stellt erneut zu.
                throw $exception;
            }

            $existing = WebhookEvent::query()
                ->where('provider_event_id', $event->eventId)
                ->first();

            if (! $existing instanceof WebhookEvent) {
                throw $exception;
            }

            $existing->forceFill([
                'attempts' => (int) $existing->getAttribute('attempts') + 1,
            ])->save();

            if ($this->isRetryable($existing)) {
                return $this->reopen($existing);
            }

            if ($this->isStillInProgress($existing)) {
                // Die erste Zustellung ist noch nicht abgeschlossen oder
                // ohne Ergebnis abgebrochen. Ein 200 wuerde die Zustellkette
                // des Anbieters beenden, obwohl nichts verarbeitet wurde.
                // Die Ausnahme fuehrt zu 500; der Anbieter stellt erneut zu,
                // und nach STALE_RECEIVED_MINUTES wird erneut verarbeitet.
                throw WebhookStillProcessingException::forEvent($event->eventId);
            }

            return null;
        }
    }

    private function isRetryable(WebhookEvent $existing): bool
    {
        $status = $existing->getAttribute('processing_status');

        if ($status === WebhookProcessingStatus::FEHLGESCHLAGEN) {
            return true;
        }

        if ($status !== WebhookProcessingStatus::EMPFANGEN) {
            return false;
        }

        $received = $existing->getAttribute('received_at');

        return ! $received instanceof Carbon
            || $received->lt(now()->subMinutes(self::STALE_RECEIVED_MINUTES));
    }

    /**
     * EMPFANGEN ohne Abschluss: die Verarbeitung laeuft noch oder wurde vor
     * dem Commit hart abgebrochen. Ein solcher Datensatz gilt nie als
     * abschliessend verarbeitet.
     */
    private function isStillInProgress(WebhookEvent $existing): bool
    {
        return $existing->getAttribute('processing_status') === WebhookProcessingStatus::EMPFANGEN
            && $existing->getAttribute('processed_at') === null;
    }

    private function reopen(WebhookEvent $existing): WebhookEvent
    {
        $existing->forceFill([
            'processing_status' => WebhookProcessingStatus::EMPFANGEN,
            'error_code' => null,
            'error_message' => null,
            'processed_at' => null,
        ])->save();

        return $existing;
    }

    /**
     * Verletzung des eindeutigen Schluessels, datenbankuebergreifend anhand
     * des SQLSTATE erkannt (23000 bei MySQL, MariaDB und SQLite, 23505 bei
     * PostgreSQL).
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        return in_array((string) $sqlState, ['23000', '23505'], true);
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
