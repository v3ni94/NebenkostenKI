<?php

declare(strict_types=1);

namespace App\Application\Install\Connectivity;

use InvalidArgumentException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Oeffnet die SMTP-Verbindung mit denselben Parametern wie der Mailer und
 * schliesst sie nach erfolgreicher Anmeldung wieder.
 */
final class SmtpHandshakeConnectivity implements SmtpConnectivity
{
    public function handshake(array $mailerConfig): void
    {
        $host = $mailerConfig['host'] ?? null;
        $port = $mailerConfig['port'] ?? null;

        if (! is_string($host) || $host === '' || ! is_numeric($port)) {
            throw new InvalidArgumentException('Host oder Port fehlen.');
        }

        $encryption = $mailerConfig['encryption'] ?? $mailerConfig['scheme'] ?? null;
        // Implizites TLS (Port 465) oeffnet die Verbindung verschluesselt.
        // STARTTLS auf Port 587 handelt Symfony automatisch aus.
        $tls = $encryption === 'ssl' || $encryption === 'smtps' || ($encryption === 'tls' && (int) $port === 465);

        $transport = new EsmtpTransport($host, (int) $port, $tls);

        $username = $mailerConfig['username'] ?? null;
        $password = $mailerConfig['password'] ?? null;

        if (is_string($username) && $username !== '') {
            $transport->setUsername($username);
        }

        if (is_string($password) && $password !== '') {
            $transport->setPassword($password);
        }

        try {
            $transport->start();
        } finally {
            $transport->stop();
        }
    }
}
