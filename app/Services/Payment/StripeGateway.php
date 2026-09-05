<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Payment\Contracts\CheckoutClient;
use App\Services\Payment\Dto\CheckoutSessionPayload;
use App\Services\Payment\Dto\CheckoutSessionResult;
use App\Services\Payment\Exceptions\CheckoutProviderException;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Einzige Stelle mit Netzzugriff auf den Zahlungsanbieter (Abschnitt 15.1).
 *
 * VERBINDLICHE EIGENSCHAFTEN
 *
 *  1. Gehostete Zahlungsseite, Betriebsart "payment". Es wird ausdruecklich
 *     KEIN Abonnement angelegt; ein Wechsel auf "subscription" ist hier nicht
 *     vorgesehen.
 *  2. Beträge und Positionen stammen ausschliesslich aus dem Payload und damit
 *     aus der Datenbank. Es wird kein Wert aus einem Formular verwendet.
 *  3. Die Sitzung wird ueber client_reference_id an den Abrechnungslauf und
 *     ueber metadata zusaetzlich an Lauf, Nutzer und Mandant gebunden. Die
 *     gleichen Kennungen werden ueber payment_intent_data.metadata auf die
 *     Zahlungsabsicht uebertragen, damit auch payment_intent-Ereignisse
 *     zuordenbar sind.
 *  4. Der Idempotency-Key verhindert, dass ein doppelt abgesendetes Formular
 *     zwei Zahlungsvorgaenge erzeugt.
 *  5. Uebertragen wird ausschliesslich, was der Payload enthaelt: eine neutrale
 *     Leistungsbezeichnung, Anzahl, Preis, Waehrung, technische Kennungen und
 *     die Rueckleitungsadressen. Mietvertraege, Abrechnungsbelege und
 *     Mieter-PDFs erreichen den Anbieter nicht.
 *
 * Im Testlauf wird diese Klasse nicht verwendet. Die Tests binden eine eigene
 * Umsetzung von CheckoutClient, damit kein echter Aufruf entsteht.
 */
final class StripeGateway implements CheckoutClient
{
    private ?StripeClient $client = null;

    private ?StripeConfiguration $configuration;

    public function __construct(?StripeConfiguration $configuration = null)
    {
        $this->configuration = $configuration;
    }

    private function configuration(): StripeConfiguration
    {
        return $this->configuration ??= StripeConfiguration::fromConfig();
    }

    public function createCheckoutSession(CheckoutSessionPayload $payload): CheckoutSessionResult
    {
        try {
            $session = $this->client()->checkout->sessions->create(
                $this->parameters($payload),
                ['idempotency_key' => $payload->idempotencyKey],
            );
        } catch (ApiErrorException $exception) {
            throw CheckoutProviderException::sessionNotCreated($exception);
        }

        return $this->result($session, $payload);
    }

    public function expireCheckoutSession(string $sessionId): void
    {
        try {
            $this->client()->checkout->sessions->expire($sessionId);
        } catch (ApiErrorException) {
            // Eine bereits abgelaufene oder bezahlte Sitzung laesst sich nicht
            // beenden. Das ist kein Fehler des Abbruchs: der Zustand des Laufs
            // wird ausschliesslich ueber den Webhook gefuehrt.
        }
    }

    /**
     * Uebertragene Felder. Diese Methode ist der Nachweis der
     * Datensparsamkeit und wird gesondert getestet.
     *
     * @return array<string, mixed>
     */
    public function parameters(CheckoutSessionPayload $payload): array
    {
        $lineItems = [[
            'quantity' => $payload->quantity,
            'price_data' => [
                'currency' => $payload->currency,
                'unit_amount' => $payload->unitAmountGrossCent,
                'product_data' => ['name' => $payload->productName],
            ],
        ]];

        if ($payload->hasBaseAmount()) {
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $payload->currency,
                    'unit_amount' => $payload->baseAmountGrossCent,
                    'product_data' => ['name' => $payload->baseProductName ?? 'Grundpreis je Abrechnungslauf'],
                ],
            ];
        }

        $parameters = [
            // Einmalzahlung. Kein Abonnement (Abschnitt 15.1).
            'mode' => 'payment',
            'line_items' => $lineItems,
            'client_reference_id' => $payload->clientReferenceId,
            'metadata' => $payload->metadata,
            // Dieselben technischen Kennungen auf der Zahlungsabsicht. Der
            // Anbieter kopiert Sitzungsmetadaten nicht; ohne diese Angabe
            // waeren payment_intent-Ereignisse nicht zuordenbar.
            'payment_intent_data' => ['metadata' => $payload->metadata],
            'success_url' => $payload->successUrl,
            'cancel_url' => $payload->cancelUrl,
            'locale' => 'de',
        ];

        if ($payload->customerEmail !== null) {
            // Die E-Mail-Adresse des Kontoinhabers ist fuer den Zahlungsbeleg
            // des Anbieters erforderlich. Sie ist kein Mieter- und kein
            // Belegdatum.
            $parameters['customer_email'] = $payload->customerEmail;
        }

        return $parameters;
    }

    private function result(Session $session, CheckoutSessionPayload $payload): CheckoutSessionResult
    {
        $id = is_string($session->id) ? $session->id : '';
        $url = is_string($session->url) ? $session->url : '';

        if ($id === '' || $url === '') {
            throw CheckoutProviderException::invalidResponse();
        }

        $total = $session->amount_total;
        $currency = $session->currency;
        $intent = $session->payment_intent;

        return new CheckoutSessionResult(
            $id,
            $url,
            is_int($total) ? $total : $payload->totalGrossCent(),
            is_string($currency) ? $currency : $payload->currency,
            is_string($intent) ? $intent : null,
        );
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient($this->configuration()->secretKey());
    }
}
