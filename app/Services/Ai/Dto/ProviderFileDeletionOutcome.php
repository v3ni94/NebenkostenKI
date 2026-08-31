<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

use App\Enums\DeletionStatus;

/**
 * Ergebnis der Loeschung einer temporaer beim Provider angelegten Datei.
 *
 * Nach Abschnitt 6.3 Schritt 14 und 18 wird die Loeschung als datensparsamer
 * Nachweis protokolliert, also Zeitpunkt, Status und Fehlercode, ohne
 * Dateiinhalte.
 *
 * VERBINDLICH: Die Provider-Datei-ID wird nach Abschluss der Verarbeitung
 * nicht dauerhaft gespeichert (Abschnitt 6.4). Dieses DTO fuehrt daher nur
 * einen gekuerzten SHA-256-Hash der ID mit. Der Hash genuegt, um zwei
 * Loeschvorgaenge im Protokoll zu unterscheiden, und ist keine verwertbare
 * Referenz auf eine Providerdatei.
 */
final class ProviderFileDeletionOutcome
{
    private function __construct(
        public readonly string $providerKey,
        public readonly string $providerFileHandleHash,
        public readonly DeletionStatus $status,
        public readonly string $attemptedAt,
        public readonly int $attempts,
        public readonly ?string $errorCode = null,
    ) {}

    public static function deleted(string $providerKey, string $providerFileId, int $attempts = 1): self
    {
        return new self(
            $providerKey,
            self::hashHandle($providerFileId),
            DeletionStatus::ERFOLGREICH,
            self::now(),
            $attempts,
        );
    }

    public static function failed(string $providerKey, string $providerFileId, string $errorCode, int $attempts = 1): self
    {
        return new self(
            $providerKey,
            self::hashHandle($providerFileId),
            DeletionStatus::FEHLGESCHLAGEN,
            self::now(),
            $attempts,
            $errorCode,
        );
    }

    /**
     * Es wurde keine Providerdatei angelegt, weil die Datei direkt im
     * Verarbeitungsrequest uebergeben wurde. Das ist der Regelfall.
     */
    public static function notRequired(string $providerKey): self
    {
        return new self(
            $providerKey,
            self::hashHandle('inline'),
            DeletionStatus::NICHT_ERFORDERLICH,
            self::now(),
            0,
        );
    }

    public function isPrivacyAlert(): bool
    {
        return $this->status->isPrivacyAlert();
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toLogContext(): array
    {
        return [
            'provider' => $this->providerKey,
            'provider_file_handle_hash' => $this->providerFileHandleHash,
            'deletion_status' => $this->status->value,
            'attempted_at' => $this->attemptedAt,
            'attempts' => $this->attempts,
            'error_code' => $this->errorCode,
        ];
    }

    private static function hashHandle(string $providerFileId): string
    {
        return substr(hash('sha256', $providerFileId), 0, 16);
    }

    private static function now(): string
    {
        return gmdate('c');
    }
}
