<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

/**
 * Der Provider hat die Anfrage wegen einer Ratenbegrenzung abgelehnt.
 *
 * Berechtigt zum Fallback, sofern ai.fallback_enabled gesetzt und der
 * Fallbackprovider freigegeben ist.
 */
final class RateLimitException extends AiException
{
    private ?int $retryAfterSeconds = null;

    public static function forProvider(string $providerKey, ?int $retryAfterSeconds = null): self
    {
        $exception = new self(sprintf(
            'Provider "%s" hat die Anfrage wegen einer Ratenbegrenzung abgelehnt.',
            $providerKey,
        ));
        $exception->retryAfterSeconds = $retryAfterSeconds;

        return $exception;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
