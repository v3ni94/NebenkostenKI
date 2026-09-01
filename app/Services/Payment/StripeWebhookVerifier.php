<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Payment\Dto\VerifiedWebhookEvent;
use App\Services\Payment\Exceptions\WebhookVerificationException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;

/**
 * Signaturpruefung der Providerbenachrichtigungen (Abschnitt 15.1, 19).
 *
 * VERBINDLICH:
 *  1. Die Pruefung ist zwingend. Es gibt keinen Schalter und keine Umgebung, in
 *     der sie uebersprungen wird. Fehlt das Signaturgeheimnis, wird die
 *     Benachrichtigung abgewiesen und nicht ersatzweise akzeptiert.
 *  2. Geprueft wird die ROHE Nutzlast, nicht ein bereits geparster Inhalt.
 *     Jede Umformung wuerde die Signatur unbrauchbar machen.
 *  3. Die Zeittoleranz begrenzt Wiedereinspielungen alter Benachrichtigungen.
 *  4. Nur diese Klasse erzeugt VerifiedWebhookEvent. Eine ungeprueft
 *     eingelesene Nutzlast kann die Verarbeitung nicht erreichen.
 *
 * Die Pruefung verwendet die Umsetzung des offiziellen Anbieter-SDK. Es wird
 * bewusst kein eigener Signaturalgorithmus nachgebaut.
 */
final class StripeWebhookVerifier
{
    public const string SIGNATURE_HEADER = 'Stripe-Signature';

    private ?StripeConfiguration $configuration;

    public function __construct(?StripeConfiguration $configuration = null)
    {
        $this->configuration = $configuration;
    }

    /**
     * @throws WebhookVerificationException
     */
    public function verify(string $rawPayload, ?string $signatureHeader): VerifiedWebhookEvent
    {
        if ($signatureHeader === null || trim($signatureHeader) === '') {
            throw WebhookVerificationException::headerMissing();
        }

        $configuration = $this->configuration();

        if (! $configuration->hasWebhookSecret()) {
            // Ohne Geheimnis ist keine Aussage ueber die Echtheit moeglich. Die
            // Benachrichtigung wird abgewiesen, nicht vertrauensvoll akzeptiert.
            throw WebhookVerificationException::invalid();
        }

        try {
            WebhookSignature::verifyHeader(
                $rawPayload,
                $signatureHeader,
                $configuration->webhookSecret(),
                StripeConfiguration::SIGNATURE_TOLERANCE_SECONDS,
            );
        } catch (SignatureVerificationException) {
            throw WebhookVerificationException::invalid();
        }

        return $this->decode($rawPayload);
    }

    /**
     * @throws WebhookVerificationException
     */
    private function decode(string $rawPayload): VerifiedWebhookEvent
    {
        $decoded = json_decode($rawPayload, true);

        if (! is_array($decoded)) {
            throw WebhookVerificationException::unreadablePayload();
        }

        $id = $decoded['id'] ?? null;
        $type = $decoded['type'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($type) || $type === '') {
            throw WebhookVerificationException::unreadablePayload();
        }

        $data = $decoded['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        if (! is_array($object)) {
            throw WebhookVerificationException::unreadablePayload();
        }

        /** @var array<string, mixed> $object */
        return new VerifiedWebhookEvent($id, $type, $object, hash('sha256', $rawPayload));
    }

    private function configuration(): StripeConfiguration
    {
        return $this->configuration ??= StripeConfiguration::fromConfig();
    }
}
