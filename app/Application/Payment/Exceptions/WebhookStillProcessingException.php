<?php

declare(strict_types=1);

namespace App\Application\Payment\Exceptions;

use RuntimeException;

/**
 * Eine Providerbenachrichtigung wurde erneut zugestellt, waehrend ihre erste
 * Verarbeitung noch nicht abgeschlossen ist (Status EMPFANGEN ohne
 * processed_at). Sie darf nicht mit 200 quittiert werden, weil der Anbieter
 * die Zustellkette dann beendet, obwohl nichts verarbeitet wurde, etwa nach
 * einem harten Prozessabbruch vor dem Commit. Der Controller antwortet 500,
 * der Anbieter stellt erneut zu.
 */
final class WebhookStillProcessingException extends RuntimeException
{
    public static function forEvent(string $eventId): self
    {
        return new self(sprintf(
            'Die Benachrichtigung %s ist noch in Verarbeitung oder ohne Ergebnis abgebrochen. '
            .'Sie wird nicht als abgeschlossen quittiert; eine erneute Zustellung wird verarbeitet, '
            .'sobald die Wartezeit abgelaufen ist.',
            $eventId,
        ));
    }
}
