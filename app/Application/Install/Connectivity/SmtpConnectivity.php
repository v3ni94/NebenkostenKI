<?php

declare(strict_types=1);

namespace App\Application\Install\Connectivity;

/**
 * Verbindungsprobe zum SMTP-Server.
 *
 * Die Schnittstelle existiert, damit der Konfigurationscheck im Testlauf ohne
 * Netzzugriff arbeitet. Produktiv ist SmtpHandshakeConnectivity gebunden.
 */
interface SmtpConnectivity
{
    /**
     * Baut die Verbindung auf, fuehrt den Handshake und die Anmeldung durch
     * und trennt wieder. Es wird keine Nachricht versendet. Wirft bei Fehler.
     *
     * @param  array<string, mixed>  $mailerConfig  Abschnitt mail.mailers.<name>
     */
    public function handshake(array $mailerConfig): void;
}
