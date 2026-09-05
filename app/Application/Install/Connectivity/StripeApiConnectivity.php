<?php

declare(strict_types=1);

namespace App\Application\Install\Connectivity;

use Stripe\StripeClient;

/**
 * Ruft den Kontostand ab. Der Aufruf ist lesend und erzeugt kein Objekt.
 */
final class StripeApiConnectivity implements StripeConnectivity
{
    public function verifySecretKey(string $secretKey): string
    {
        $client = new StripeClient(['api_key' => $secretKey]);
        $balance = $client->balance->retrieve();

        return $balance->livemode ? 'live' : 'test';
    }
}
