<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use Throwable;

/**
 * Technischer Fehler beim Providerzugriff, also Netzwerkfehler, Timeout,
 * Serverfehler oder unerwartete Antwortstruktur.
 *
 * Die Meldung enthaelt ausschliesslich technische Metadaten. Der
 * Antwortbody wird nicht uebernommen.
 */
final class ProviderTransportException extends AiException
{
    public static function httpStatus(string $providerKey, int $statusCode, ?string $errorCode = null): self
    {
        return new self(sprintf(
            'Provider "%s" antwortete mit HTTP-Status %d%s.',
            $providerKey,
            $statusCode,
            $errorCode !== null && $errorCode !== '' ? sprintf(' (Fehlercode %s)', $errorCode) : '',
        ));
    }

    public static function network(string $providerKey, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Provider "%s" ist technisch nicht erreichbar.', $providerKey),
            0,
            // Der vorherige Fehler wird nur zur Ursachenkette uebernommen. Er
            // darf keine Anfrage- oder Antwortinhalte enthalten, weil der
            // Transportadapter Bodies nicht in Meldungen uebernimmt.
            $previous,
        );
    }

    public static function malformedResponse(string $providerKey): self
    {
        return new self(sprintf(
            'Antwort von Provider "%s" hat nicht die erwartete Struktur.',
            $providerKey,
        ));
    }
}
