<?php

declare(strict_types=1);

namespace App\Application\Documents\Dto;

use App\Enums\DeletionStatus;

/**
 * Ergebnis eines Loeschvorgangs, so wie er in source_deletion_events
 * protokolliert wird.
 *
 * DATENSCHUTZ: Ohne Dateiinhalt, ohne Dateiname, ohne Storage-Key.
 */
final class DeletionOutcome
{
    public function __construct(
        public readonly DeletionStatus $localStatus,
        public readonly DeletionStatus $providerStatus,
        public readonly int $attempt,
        public readonly bool $alreadyDeleted = false,
        public readonly ?string $errorCode = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->localStatus === DeletionStatus::ERFOLGREICH
            && ($this->providerStatus === DeletionStatus::ERFOLGREICH
                || $this->providerStatus === DeletionStatus::NICHT_ERFORDERLICH);
    }
}
