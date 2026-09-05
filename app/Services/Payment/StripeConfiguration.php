<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Payment\Exceptions\PaymentConfigurationException;

/**
 * Zugangsdaten und Grundeinstellungen der Zahlungsanbindung (Abschnitt 15.1).
 *
 * VERBINDLICH:
 *  - Schluessel stehen ausschliesslich in der Umgebung, niemals im Code, in
 *    Fixtures oder in Logs. Diese Klasse gibt sie nur an StripeGateway weiter
 *    und besitzt bewusst keine Ausgabemethode fuer die Oberflaeche.
 *  - Fehlt ein Schluessel, wird kein Checkout eingeleitet. Ein Zahlungsvorgang
 *    ohne pruefbare Signatur waere ein Sicherheitsrisiko.
 *
 * Die Werte werden ausschliesslich ueber config() gelesen, damit config:cache
 * greift. Ein direkter env()-Zugriff ausserhalb des Konfigurationsverzeichnisses
 * waere im gecachten Zustand null und damit ein stiller Ausfall der Zahlung.
 *
 * ERFORDERLICHE ERGAENZUNG VOR LIVEGANG (im Uebergabebericht vermerkt):
 * config/services.php erhaelt den Abschnitt
 *
 *     'stripe' => [
 *         'secret' => env('STRIPE_SECRET'),
 *         'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
 *     ],
 *
 * Solange der Abschnitt fehlt, bleiben beide Werte null. Es wird dann kein
 * Checkout eingeleitet und keine Benachrichtigung akzeptiert; ein
 * ungeschuetzter Betrieb ist damit ausgeschlossen.
 */
final readonly class StripeConfiguration
{
    /**
     * Zulaessige Zeitabweichung der Webhook-Signatur in Sekunden. Der Wert
     * entspricht der Empfehlung des Anbieters und begrenzt Wiedereinspielungen.
     */
    public const int SIGNATURE_TOLERANCE_SECONDS = 300;

    private function __construct(
        private ?string $secretKey,
        private ?string $webhookSecret,
        public string $currency,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            self::readString('services.stripe.secret'),
            self::readString('services.stripe.webhook_secret'),
            self::readCurrency(),
        );
    }

    /**
     * Fuer Tests: ausdruecklich gesetzte Werte ohne Umgebungszugriff. Es werden
     * niemals echte Schluessel verwendet.
     */
    public static function of(?string $secretKey, ?string $webhookSecret, string $currency = 'eur'): self
    {
        return new self($secretKey, $webhookSecret, $currency);
    }

    public function hasSecretKey(): bool
    {
        return $this->secretKey !== null;
    }

    public function hasWebhookSecret(): bool
    {
        return $this->webhookSecret !== null;
    }

    /**
     * @throws PaymentConfigurationException
     */
    public function secretKey(): string
    {
        if ($this->secretKey === null) {
            throw PaymentConfigurationException::missing('STRIPE_SECRET (config services.stripe.secret)');
        }

        return $this->secretKey;
    }

    /**
     * @throws PaymentConfigurationException
     */
    public function webhookSecret(): string
    {
        if ($this->webhookSecret === null) {
            throw PaymentConfigurationException::missing(
                'STRIPE_WEBHOOK_SECRET (config services.stripe.webhook_secret)'
            );
        }

        return $this->webhookSecret;
    }

    /**
     * Waehrung in der Schreibweise des Anbieters, also klein.
     */
    public function currencyCode(): string
    {
        return strtolower($this->currency);
    }

    private static function readString(string $key): ?string
    {
        $value = config($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function readCurrency(): string
    {
        $value = config('smartabrechnen.pricing.currency');

        return is_string($value) && $value !== '' ? $value : 'eur';
    }
}
