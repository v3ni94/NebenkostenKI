<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Verstaendliche Fehlermeldung mit Handlungsempfehlung.
 *
 * Die Nachricht enthaelt keine technischen Rohdaten, keine Stacktraces und
 * keine Providerantworten. Sie sagt, was nicht funktioniert hat und was der
 * Nutzer als naechstes tun kann (Masterprompt 16).
 *
 * Kritische Kontonachricht, weil ein stiller Fehler den Nutzer im Glauben
 * lassen wuerde, die Abrechnung laufe weiter.
 */
final class VerarbeitungsfehlerMail extends TransactionalMail
{
    public function __construct(
        private readonly string $anrede,
        private readonly string $objekt,
        private readonly int $jahr,
        private readonly string $sachverhalt,
        private readonly string $empfehlung,
        private readonly string $portalUrl,
    ) {}

    public function template(): string
    {
        return 'verarbeitungsfehler';
    }

    public function betreff(): string
    {
        return sprintf('Ihre Abrechnung für %s benötigt Ihre Mithilfe', $this->objekt);
    }

    public function blade(): string
    {
        return 'emails.transaktion.verarbeitungsfehler';
    }

    public function istKritisch(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function daten(): array
    {
        return [
            'anrede' => $this->anrede,
            'objekt' => $this->objekt,
            'jahr' => $this->jahr,
            'sachverhalt' => $this->sachverhalt,
            'empfehlung' => $this->empfehlung,
            'portalUrl' => $this->portalUrl,
        ];
    }
}
