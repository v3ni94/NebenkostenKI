<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

use App\Services\Payment\Dto\CheckoutSessionPayload;
use App\Services\Payment\Dto\CheckoutSessionResult;

/**
 * Zugang zum Zahlungsanbieter.
 *
 * Die Schnittstelle existiert, damit im Testlauf niemals ein echter Aufruf
 * stattfindet (Abschnitt 23.3). Die Anwendungsschicht kennt ausschliesslich
 * diese Schnittstelle; die einzige Umsetzung mit Netzzugriff ist
 * App\Services\Payment\StripeGateway.
 */
interface CheckoutClient
{
    /**
     * Legt eine gehostete Zahlungsseite fuer eine Einmalzahlung an.
     */
    public function createCheckoutSession(CheckoutSessionPayload $payload): CheckoutSessionResult;

    /**
     * Beendet eine noch offene Zahlungsseite, damit ein abgebrochener Vorgang
     * nicht spaeter unbeabsichtigt bezahlt werden kann.
     */
    public function expireCheckoutSession(string $sessionId): void;
}
