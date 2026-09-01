<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Enums\WebhookSignatureStatus;
use App\Services\Payment\Exceptions\WebhookVerificationException;
use App\Services\Payment\StripeConfiguration;
use App\Services\Payment\StripeWebhookVerifier;
use Tests\TestCase;

/**
 * Signaturpruefung der Providerbenachrichtigungen (Abschnitt 15.1).
 *
 * Die Signaturen werden im Test selbst erzeugt. Es wird ausschliesslich das
 * Platzhaltergeheimnis verwendet, niemals ein echter Schluessel.
 */
final class StripeWebhookVerifierTest extends TestCase
{
    private const string GEHEIMNIS = 'whsec_test_placeholder';

    private function verifizierer(?string $geheimnis = self::GEHEIMNIS): StripeWebhookVerifier
    {
        return new StripeWebhookVerifier(StripeConfiguration::of(null, $geheimnis));
    }

    private function nutzlast(): string
    {
        return (string) json_encode([
            'id' => 'evt_test_0001',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_0001',
                'amount_total' => 2490,
                'currency' => 'EUR',
                'payment_intent' => 'pi_test_0001',
                'metadata' => ['billing_run_id' => '01LAUF00000000000000000000'],
            ]],
        ]);
    }

    private function signatur(string $payload, ?string $geheimnis = self::GEHEIMNIS, ?int $zeitpunkt = null): string
    {
        $zeitpunkt ??= time();

        return sprintf(
            't=%d,v1=%s',
            $zeitpunkt,
            hash_hmac('sha256', $zeitpunkt.'.'.$payload, (string) $geheimnis),
        );
    }

    public function test_eine_gueltige_signatur_wird_angenommen(): void
    {
        $payload = $this->nutzlast();

        $ereignis = $this->verifizierer()->verify($payload, $this->signatur($payload));

        self::assertSame('evt_test_0001', $ereignis->eventId);
        self::assertSame('checkout.session.completed', $ereignis->eventType);
        self::assertSame('cs_test_0001', $ereignis->checkoutSessionId());
        self::assertSame('pi_test_0001', $ereignis->paymentIntentId());
        self::assertSame(2490, $ereignis->amountCent());
        self::assertSame('eur', $ereignis->currency());
        self::assertSame('01LAUF00000000000000000000', $ereignis->metadata('billing_run_id'));
        self::assertSame(hash('sha256', $payload), $ereignis->payloadDigest);
    }

    public function test_eine_falsche_signatur_wird_abgewiesen(): void
    {
        $payload = $this->nutzlast();

        try {
            $this->verifizierer()->verify($payload, $this->signatur($payload, 'whsec_anderes_geheimnis'));
            self::fail('Eine falsche Signatur muss abgewiesen werden.');
        } catch (WebhookVerificationException $ausnahme) {
            self::assertSame(WebhookSignatureStatus::UNGUELTIG, $ausnahme->signatureStatus);
        }
    }

    public function test_eine_fehlende_signatur_wird_abgewiesen(): void
    {
        try {
            $this->verifizierer()->verify($this->nutzlast(), null);
            self::fail('Eine fehlende Signatur muss abgewiesen werden.');
        } catch (WebhookVerificationException $ausnahme) {
            self::assertSame(WebhookSignatureStatus::FEHLT, $ausnahme->signatureStatus);
        }
    }

    public function test_eine_veraenderte_nutzlast_wird_abgewiesen(): void
    {
        $payload = $this->nutzlast();
        $signatur = $this->signatur($payload);

        $this->expectException(WebhookVerificationException::class);

        $this->verifizierer()->verify(str_replace('2490', '1', $payload), $signatur);
    }

    public function test_ohne_geheimnis_wird_nicht_ersatzweise_akzeptiert(): void
    {
        $payload = $this->nutzlast();

        try {
            $this->verifizierer(null)->verify($payload, $this->signatur($payload));
            self::fail('Ohne Signaturgeheimnis darf nichts akzeptiert werden.');
        } catch (WebhookVerificationException $ausnahme) {
            self::assertSame(WebhookSignatureStatus::UNGUELTIG, $ausnahme->signatureStatus);
        }
    }

    public function test_eine_unlesbare_nutzlast_wird_abgewiesen(): void
    {
        $payload = 'kein json';

        $this->expectException(WebhookVerificationException::class);

        $this->verifizierer()->verify($payload, $this->signatur($payload));
    }

    public function test_eine_nutzlast_ohne_datenobjekt_wird_abgewiesen(): void
    {
        $payload = (string) json_encode(['id' => 'evt_test_0002', 'type' => 'checkout.session.completed']);

        $this->expectException(WebhookVerificationException::class);

        $this->verifizierer()->verify($payload, $this->signatur($payload));
    }
}
