<?php

declare(strict_types=1);

namespace App\Services\Payment\Dto;

/**
 * Eine Providerbenachrichtigung, deren Signatur geprueft ist.
 *
 * Eine Instanz dieser Klasse kann ausschliesslich von
 * App\Services\Payment\StripeWebhookVerifier erzeugt werden. Die
 * Anwendungsschicht nimmt nur diesen Typ an; eine ungeprueft eingelesene
 * Nutzlast kann daher nicht in die Verarbeitung gelangen (Abschnitt 15.1).
 *
 * @phpstan-type StripeObject array<string, mixed>
 */
final readonly class VerifiedWebhookEvent
{
    /**
     * @param  array<string, mixed>  $object  Datenobjekt der Benachrichtigung
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public array $object,
        public string $payloadDigest,
    ) {}

    public function string(string $key): ?string
    {
        $value = $this->object[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function integer(string $key): ?int
    {
        $value = $this->object[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * Metadatenwert der Benachrichtigung. Metadaten enthalten ausschliesslich
     * technische Kennungen.
     */
    public function metadata(string $key): ?string
    {
        $metadata = $this->object['metadata'] ?? null;

        if (! is_array($metadata)) {
            return null;
        }

        $value = $metadata[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Kennung der Zahlungsseite. Sie steht je Ereignisart an
     * unterschiedlicher Stelle.
     */
    public function checkoutSessionId(): ?string
    {
        return $this->string('id') !== null && str_starts_with((string) $this->string('id'), 'cs_')
            ? $this->string('id')
            : null;
    }

    /**
     * Kennung der Zahlungsabsicht. Bei einer Zahlungsseite steht sie im Feld
     * payment_intent, bei einer Belastung im Feld payment_intent des Objekts.
     */
    public function paymentIntentId(): ?string
    {
        $intent = $this->string('payment_intent');

        if ($intent !== null) {
            return $intent;
        }

        $id = $this->string('id');

        return $id !== null && str_starts_with($id, 'pi_') ? $id : null;
    }

    /**
     * Gezahlter Betrag in Cent, je Ereignisart aus unterschiedlichen Feldern.
     */
    public function amountCent(): ?int
    {
        foreach (['amount_total', 'amount_received', 'amount'] as $key) {
            $value = $this->integer($key);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    public function currency(): ?string
    {
        $currency = $this->string('currency');

        return $currency === null ? null : strtolower($currency);
    }

    /**
     * Erstatteter Betrag in Cent.
     */
    public function refundedAmountCent(): ?int
    {
        $value = $this->object['amount_refunded'] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return $this->amountCent();
    }
}
