<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Payment\Contracts\CheckoutClient;
use App\Services\Payment\Dto\CheckoutSessionPayload;
use App\Services\Payment\Dto\CheckoutSessionResult;

/**
 * Zahlungsanbieter im Testlauf.
 *
 * VERBINDLICH: Es entsteht kein Netzverkehr und kein echter Aufruf. Der Fake
 * merkt sich die uebergebenen Payloads, damit geprueft werden kann, welche
 * Felder tatsaechlich uebertragen wuerden.
 */
final class FakeCheckoutClient implements CheckoutClient
{
    /**
     * @var list<CheckoutSessionPayload>
     */
    public array $payloads = [];

    /**
     * @var list<string>
     */
    public array $expiredSessions = [];

    public int $sessionCounter = 0;

    public function createCheckoutSession(CheckoutSessionPayload $payload): CheckoutSessionResult
    {
        $this->payloads[] = $payload;
        $this->sessionCounter++;

        $id = sprintf('cs_test_fake_%03d', $this->sessionCounter);

        return new CheckoutSessionResult(
            $id,
            'https://checkout.example.test/'.$id,
            $payload->totalGrossCent(),
            $payload->currency,
        );
    }

    public function expireCheckoutSession(string $sessionId): void
    {
        $this->expiredSessions[] = $sessionId;
    }

    public function lastPayload(): ?CheckoutSessionPayload
    {
        return $this->payloads === [] ? null : $this->payloads[count($this->payloads) - 1];
    }
}
