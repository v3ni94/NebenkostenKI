<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Account\AuditRecorder;
use App\Application\BillingRun\BillingRunStateMachine;
use App\Application\BillingRun\IllegalStatusTransitionException;
use App\Enums\BillingRunStatus;
use App\Enums\PaymentStatus;
use App\Models\BillingRun;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payment\Contracts\CheckoutClient;

/**
 * Use Case: einen eingeleiteten Zahlungsvorgang abbrechen.
 *
 * VERBINDLICHE REGELN
 *
 *  1. Ein abgebrochener Vorgang laesst den Abrechnungslauf im Vorschauzustand.
 *     Es wird nichts freigeschaltet und keine Rechnung erzeugt.
 *  2. Eine bereits bestaetigte Zahlung wird niemals abgebrochen. Der Abbruch
 *     ist dann wirkungslos; die Rueckabwicklung laeuft ueber RefundHandling.
 *  3. Die offene Zahlungsseite beim Anbieter wird beendet, damit sie nicht
 *     spaeter noch bezahlt werden kann. Das gilt fuer JEDEN offenen Vorgang des
 *     Laufs, nicht nur fuer den juengsten.
 *  4. Der Abbruch im Browser ist nur eine Absichtserklaerung des Nutzers. Der
 *     verbindliche Zustand entsteht auch hier erst mit der signaturgeprueften
 *     Rueckmeldung; diese Klasse setzt den Vorgang deshalb lediglich auf
 *     ABGEBROCHEN und nicht auf einen Endzustand der Zahlung.
 */
final class CancelCheckout
{
    public function __construct(
        private readonly CheckoutClient $client,
        private readonly BillingRunStateMachine $stateMachine,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(BillingRun $billingRun, ?User $actor = null): bool
    {
        /** @var list<Payment> $payments */
        $payments = Payment::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->whereIn('status', [PaymentStatus::ERSTELLT->value, PaymentStatus::AUSSTEHEND->value])
            ->orderByDesc('created_at')
            ->get()
            ->all();

        foreach ($payments as $payment) {
            $sessionId = $payment->getAttribute('checkout_session_id');

            if (is_string($sessionId) && $sessionId !== '') {
                $this->client->expireCheckoutSession($sessionId);
            }

            $payment->forceFill([
                'status' => PaymentStatus::ABGEBROCHEN,
                'failure_code' => 'ABBRUCH_DURCH_NUTZER',
            ])->save();

            $this->audit->record(
                action: 'payment.checkout_cancelled',
                subject: $payment,
                actor: $actor,
                organization: is_string($billingRun->getAttribute('organization_id'))
                    ? (string) $billingRun->getAttribute('organization_id')
                    : null,
                metadata: ['abrechnungslauf' => (string) $billingRun->getKey()],
            );
        }

        if ($billingRun->getAttribute('status') !== BillingRunStatus::CHECKOUT_PENDING) {
            return $payments !== [];
        }

        try {
            $this->stateMachine->transitionTo($billingRun, BillingRunStatus::PREVIEW_READY, $actor, [
                'grund' => 'ABBRUCH_DURCH_NUTZER',
            ]);
        } catch (IllegalStatusTransitionException) {
            return false;
        }

        return true;
    }
}
