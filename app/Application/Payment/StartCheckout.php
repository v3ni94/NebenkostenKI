<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Account\AuditRecorder;
use App\Application\BillingRun\BillingRunStateMachine;
use App\Application\Payment\Dto\CheckoutConsent;
use App\Application\Payment\Dto\CheckoutStart;
use App\Application\Payment\Dto\PriceQuote;
use App\Application\Payment\Exceptions\CheckoutNotAllowedException;
use App\Application\Review\ReviewGate;
use App\Application\Wizard\AllocationKeyWorkspace;
use App\Application\Wizard\AuditReportPresenter;
use App\Application\Wizard\PrepaymentWorkspace;
use App\Application\Wizard\PreviewBuilder;
use App\Enums\BillingRunStatus;
use App\Enums\LegalDocumentPurpose;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\BillingRun;
use App\Models\LegalAcceptance;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payment\CheckoutSessionFactory;
use App\Services\Payment\Contracts\CheckoutClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Use Case: Zahlung einleiten (Schritt 11, Abschnitt 15.1).
 *
 * VERBINDLICHE REGELN
 *
 *  1. Der Preis wird unmittelbar vor dem Checkout serverseitig NEU berechnet.
 *     Es gibt keinen Parameter, mit dem ein Betrag oder eine Anzahl von aussen
 *     vorgegeben werden koennte. Ein manipulierter Formularwert ist wirkungslos.
 *  2. Ohne die Pruefbestaetigung des Nutzers und ohne die gesonderte,
 *     nicht vorangekreuzte Zustimmung zur sofortigen Vertragsausfuehrung wird
 *     kein Checkout eingeleitet.
 *  3. Die Zustimmungen werden vor der Weiterleitung in legal_acceptances
 *     protokolliert: Textfassung, Zweck, Zeitpunkt, gekuerzte IP und gehashter
 *     User-Agent.
 *  4. Der Lauf geht auf CHECKOUT_PENDING. Freigeschaltet wird ausschliesslich
 *     ueber den signaturgeprueften Webhook; die Rueckleitung im Browser ist
 *     niemals Zahlungsnachweis.
 *  5. Der Idempotency-Key wird je Zahlungsvorgang genau einmal gebildet und
 *     auf der Zahlung gespeichert. Ein doppelt abgesendetes Formular erzeugt
 *     damit keinen zweiten Zahlungsvorgang.
 *  6. Ohne gueltige Vorschau zum aktuellen Berechnungsstand und mit offenen
 *     Sperrgruenden (Kostenpruefung, Vorauszahlungen, Verteilerschluessel,
 *     Regel-Blocker) wird kein Checkout eingeleitet. Das ist die zweite
 *     Verteidigungslinie hinter der Invalidierung der Vorschau.
 */
final class StartCheckout
{
    public function __construct(
        private readonly CalculatePrice $prices,
        private readonly CheckoutSessionFactory $sessions,
        private readonly CheckoutClient $client,
        private readonly BillingRunStateMachine $stateMachine,
        private readonly AuditRecorder $audit,
        private readonly ConsentFingerprint $fingerprint,
        private readonly PreviewBuilder $preview,
        private readonly ReviewGate $gate,
        private readonly PrepaymentWorkspace $prepayments,
        private readonly AllocationKeyWorkspace $keys,
        private readonly AuditReportPresenter $report,
    ) {}

    /**
     * @throws CheckoutNotAllowedException
     */
    public function __invoke(
        BillingRun $billingRun,
        User $user,
        CheckoutConsent $consent,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutStart {
        $this->assertStatus($billingRun);
        $this->assertPreview($billingRun);
        $this->assertNoConfirmedPayment($billingRun);
        $this->assertBillingAddress($billingRun);
        $this->assertUserConfirmation($billingRun);
        $this->assertConsent($consent);

        // Serverseitige Neuberechnung. Der einzige Weg zum Betrag.
        $quote = ($this->prices)($billingRun);
        $this->prices->remember($billingRun, $quote);

        $payment = $this->openPaymentFor($billingRun, $user, $quote);

        $payload = $this->sessions->build($billingRun, $payment, $quote, $user, $successUrl, $cancelUrl);
        $session = $this->client->createCheckoutSession($payload);

        $payment->forceFill([
            'checkout_session_id' => $session->sessionId,
            'payment_intent_id' => $session->paymentIntentId ?? $payment->getAttribute('payment_intent_id'),
            'status' => PaymentStatus::AUSSTEHEND,
        ])->save();

        $this->recordConsent($billingRun, $user);

        if ($this->currentStatus($billingRun) === BillingRunStatus::PREVIEW_READY) {
            $this->stateMachine->transitionTo(
                $billingRun,
                BillingRunStatus::CHECKOUT_PENDING,
                $user,
                ['zahlung' => (string) $payment->getKey()],
            );
        }

        $this->audit->record(
            action: 'payment.checkout_started',
            subject: $payment,
            actor: $user,
            organization: is_string($billingRun->getAttribute('organization_id'))
                ? (string) $billingRun->getAttribute('organization_id')
                : null,
            metadata: [
                'abrechnungslauf' => (string) $billingRun->getKey(),
                'anzahl_abrechnungen' => $quote->statementCount,
                'brutto_cent' => $quote->grossCent,
            ],
        );

        return new CheckoutStart($payment, $quote, $session->redirectUrl);
    }

    /**
     * Vorhandener offener Zahlungsvorgang oder ein neuer.
     *
     * Ein bereits angelegter, noch nicht bezahlter Vorgang wird mit dem neu
     * berechneten Betrag weitergefuehrt. Sein Idempotency-Key bleibt bestehen,
     * damit ein doppelter Aufruf beim Anbieter dieselbe Sitzung trifft, sofern
     * sich der Betrag nicht geaendert hat. Aendert sich der Betrag, entsteht ein
     * neuer Vorgang mit neuem Schluessel, denn der Anbieter wuerde denselben
     * Schluessel mit abweichenden Daten ablehnen.
     *
     * Der Lauf wird innerhalb der Transaktion zeilenweise gesperrt. Zwei
     * gleichzeitige Absendungen (Doppelklick, zwei Browserfenster) laufen so
     * nacheinander; die zweite findet den Vorgang der ersten.
     */
    private function openPaymentFor(BillingRun $billingRun, User $user, PriceQuote $quote): Payment
    {
        return DB::transaction(function () use ($billingRun, $user, $quote): Payment {
            BillingRun::query()
                ->whereKey($billingRun->getKey())
                ->lockForUpdate()
                ->first();

            $existing = Payment::query()
                ->where('billing_run_id', $billingRun->getKey())
                ->whereIn('status', [PaymentStatus::ERSTELLT->value, PaymentStatus::AUSSTEHEND->value])
                ->orderByDesc('created_at')
                ->first();

            if ($existing instanceof Payment
                && (int) $existing->getAttribute('amount_cent') === $quote->grossCent) {
                return $existing;
            }

            if ($existing instanceof Payment) {
                // Der Betrag hat sich geaendert. Der alte Vorgang wird
                // abgebrochen, damit er nicht spaeter bezahlt werden kann.
                $existing->forceFill(['status' => PaymentStatus::ABGEBROCHEN])->save();

                $sessionId = $existing->getAttribute('checkout_session_id');

                if (is_string($sessionId) && $sessionId !== '') {
                    $this->client->expireCheckoutSession($sessionId);
                }
            }

            /** @var Payment $payment */
            $payment = Payment::query()->create([
                'organization_id' => $billingRun->getAttribute('organization_id'),
                'billing_run_id' => $billingRun->getKey(),
                'user_id' => $user->getKey(),
                'provider' => PaymentProvider::STRIPE,
                'idempotency_key' => (string) Str::uuid(),
                'amount_cent' => $quote->grossCent,
                'currency' => $quote->currency,
                'statement_count' => $quote->statementCount,
                'unit_price_gross_cent' => $quote->unitGrossCent,
                'base_price_gross_cent' => $quote->baseGrossCent,
                'status' => PaymentStatus::ERSTELLT,
            ]);

            return $payment;
        });
    }

    /**
     * Zustimmungen datensparsam protokollieren (Abschnitt 2.3).
     */
    private function recordConsent(BillingRun $billingRun, User $user): void
    {
        $purposes = [
            [LegalDocumentPurpose::SOFORTIGE_VERTRAGSAUSFUEHRUNG, CheckoutTexts::IMMEDIATE_PERFORMANCE],
            [LegalDocumentPurpose::AGB, CheckoutTexts::TERMS],
        ];

        foreach ($purposes as [$purpose, $text]) {
            LegalAcceptance::query()->create([
                'user_id' => $user->getKey(),
                'organization_id' => $billingRun->getAttribute('organization_id'),
                'billing_run_id' => $billingRun->getKey(),
                'purpose' => $purpose,
                'document_version' => CheckoutTexts::VERSION,
                'document_hash' => CheckoutTexts::hash($text),
                'accepted_at' => now(),
                'ip_truncated' => $this->fingerprint->truncatedIp(),
                'user_agent_hash' => $this->fingerprint->userAgentHash(),
            ]);
        }
    }

    /**
     * @throws CheckoutNotAllowedException
     */
    private function assertStatus(BillingRun $billingRun): void
    {
        $status = $this->currentStatus($billingRun);

        if ($status !== null && $status->isPaid()) {
            throw CheckoutNotAllowedException::alreadyPaid();
        }

        if (! in_array($status, [BillingRunStatus::PREVIEW_READY, BillingRunStatus::CHECKOUT_PENDING], true)) {
            throw CheckoutNotAllowedException::wrongStatus();
        }

        if ($billingRun->getAttribute('active_calculation_snapshot_id') === null) {
            throw CheckoutNotAllowedException::snapshotMissing();
        }
    }

    /**
     * Gueltige Vorschau zum aktuellen Berechnungsstand und keine offenen
     * Sperrgruende. Die Reihenfolge entspricht dem Ablauf: Kostenpruefung
     * (Schritt 6), Vorauszahlungen (Schritt 7), Verteilerschluessel
     * (Schritt 8), Pruefbericht (Schritt 9).
     *
     * @throws CheckoutNotAllowedException
     */
    private function assertPreview(BillingRun $billingRun): void
    {
        if (! $this->preview->isValid($billingRun)) {
            throw CheckoutNotAllowedException::previewInvalid();
        }

        $grund = $this->blockingReason($billingRun);

        if ($grund !== null) {
            throw CheckoutNotAllowedException::blocked($grund);
        }
    }

    /**
     * Erster offener Sperrgrund oder null.
     */
    public function blockingReason(BillingRun $billingRun): ?string
    {
        $grund = $this->gate->reason($billingRun);

        if ($grund !== null) {
            return $grund;
        }

        $offen = $this->prepayments->openReasons($billingRun);

        if ($offen !== []) {
            return 'Schritt 7 ist noch nicht abgeschlossen. '.$offen[0];
        }

        $blockiert = $this->keys->blockingReasons($billingRun);

        if ($blockiert !== []) {
            return 'Schritt 8 ist noch nicht abgeschlossen. '.$blockiert[0];
        }

        return $this->report->blockingReason($billingRun);
    }

    /**
     * Ein Lauf, zu dem bereits ein Zahlungseingang vorliegt, der noch nicht
     * zugeordnet werden konnte, wird nicht ein zweites Mal bezahlt.
     *
     * @throws CheckoutNotAllowedException
     */
    private function assertNoConfirmedPayment(BillingRun $billingRun): void
    {
        $confirmed = Payment::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', PaymentStatus::BEZAHLT->value)
            ->exists();

        if ($confirmed) {
            throw CheckoutNotAllowedException::paymentAlreadyReceived();
        }
    }

    /**
     * Die Rechnung der Hausverwaltung Mueller GmbH braucht die vollstaendige
     * Anschrift des Leistungsempfaengers. Sie wird vor dem Checkout verlangt,
     * weil eine festgeschriebene Rechnung nicht nachgebessert werden kann.
     *
     * @throws CheckoutNotAllowedException
     */
    private function assertBillingAddress(BillingRun $billingRun): void
    {
        if (! self::hasBillingAddress($billingRun)) {
            throw CheckoutNotAllowedException::billingAddressMissing();
        }
    }

    /**
     * True, wenn Strasse, Postleitzahl und Ort der Rechnungsanschrift des
     * Mandanten hinterlegt sind.
     */
    public static function hasBillingAddress(BillingRun $billingRun): bool
    {
        $organizationId = $billingRun->getAttribute('organization_id');
        $organization = is_string($organizationId) ? Organization::query()->find($organizationId) : null;

        if (! $organization instanceof Organization) {
            return false;
        }

        foreach (['billing_address_line', 'billing_postal_code', 'billing_city'] as $field) {
            $value = $organization->getAttribute($field);

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws CheckoutNotAllowedException
     */
    private function assertUserConfirmation(BillingRun $billingRun): void
    {
        if ($billingRun->getAttribute('review_confirmed_at') === null
            || $billingRun->getAttribute('responsibility_confirmed_at') === null) {
            throw CheckoutNotAllowedException::reviewMissing();
        }
    }

    /**
     * @throws CheckoutNotAllowedException
     */
    private function assertConsent(CheckoutConsent $consent): void
    {
        if (! $consent->immediatePerformance) {
            throw CheckoutNotAllowedException::immediatePerformanceConsentMissing();
        }

        if (! $consent->terms) {
            throw CheckoutNotAllowedException::termsConsentMissing();
        }
    }

    private function currentStatus(BillingRun $billingRun): ?BillingRunStatus
    {
        $status = $billingRun->getAttribute('status');

        return $status instanceof BillingRunStatus ? $status : null;
    }
}
