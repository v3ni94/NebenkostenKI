<?php

declare(strict_types=1);

namespace App\Application\Install\Connectivity;

/**
 * Leichter Aufruf gegen die Stripe-API, der nichts anlegt.
 *
 * Die Schnittstelle existiert, damit der Konfigurationscheck im Testlauf ohne
 * Netzzugriff arbeitet. Produktiv ist StripeApiConnectivity gebunden.
 */
interface StripeConnectivity
{
    /**
     * Prueft den geheimen Schluessel. Wirft bei ungueltigem Schluessel oder
     * Verbindungsfehler; die Ausnahme darf den Schluessel nicht enthalten.
     *
     * @return string Betriebsmodus des Schluessels, "live" oder "test"
     */
    public function verifySecretKey(string $secretKey): string;
}
