<?php

declare(strict_types=1);

namespace App\Services\Payment\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Der Zahlungsanbieter hat die Zahlungsseite nicht angelegt oder eine
 * unbrauchbare Antwort geliefert.
 *
 * Die Meldung ist ein deutscher Nutzertext in Sie-Ansprache und wird im
 * Checkout als Formularfehler angezeigt. Die technische Ursache bleibt in der
 * verketteten Ausnahme und gelangt nur in das Anwendungslog.
 */
final class CheckoutProviderException extends RuntimeException
{
    public static function sessionNotCreated(?Throwable $previous = null): self
    {
        return new self(
            'Die Zahlungsseite konnte nicht angelegt werden. Bitte versuchen Sie es in einigen Minuten erneut.',
            0,
            $previous,
        );
    }

    public static function invalidResponse(): self
    {
        return new self('Der Zahlungsanbieter hat keine gültige Zahlungsseite zurückgemeldet.');
    }
}
