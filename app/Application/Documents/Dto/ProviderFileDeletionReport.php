<?php

declare(strict_types=1);

namespace App\Application\Documents\Dto;

use App\Enums\DeletionStatus;

/**
 * Ergebnis der Providerloeschung, datensparsam.
 *
 * DATENSCHUTZ: Es wird kein Dateiname, keine Provider-Datei-ID im Klartext und
 * keine Providerantwort gefuehrt, sondern nur Status und Fehlercode.
 */
final class ProviderFileDeletionReport
{
    private function __construct(
        public readonly DeletionStatus $status,
        public readonly ?string $errorCode = null,
    ) {}

    public static function deleted(): self
    {
        return new self(DeletionStatus::ERFOLGREICH);
    }

    public static function notRequired(): self
    {
        return new self(DeletionStatus::NICHT_ERFORDERLICH);
    }

    public static function failed(string $errorCode): self
    {
        return new self(DeletionStatus::FEHLGESCHLAGEN, substr($errorCode, 0, 120));
    }

    public function isSuccessful(): bool
    {
        return $this->status === DeletionStatus::ERFOLGREICH
            || $this->status === DeletionStatus::NICHT_ERFORDERLICH;
    }
}
