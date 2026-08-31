<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

/**
 * Der Provider ist nicht fuer den produktiven Einsatz freigegeben.
 *
 * Wird von ProviderReleaseGate geworfen, solange
 * ai.require_zero_data_retention true und ai.data_retention_approved false
 * ist. Ein Fallback darf diese Sperre nicht umgehen.
 */
final class ProviderNotReleasedException extends AiException
{
    public static function forProvider(string $providerKey, string $reason): self
    {
        return new self(sprintf(
            'Provider "%s" ist nicht freigegeben: %s',
            $providerKey,
            $reason,
        ));
    }
}
