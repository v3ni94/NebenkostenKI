<?php

declare(strict_types=1);

namespace App\Application\Payment\Dto;

/**
 * Ergebnis eines Nachholvorgangs (erneute Finalisierung, nachgeholte
 * Rechnung) fuer Befehl und Zeitplan.
 */
final class RecoveryReport
{
    /**
     * @var list<string>
     */
    private array $succeeded = [];

    /**
     * @var array<string, string>
     */
    private array $failed = [];

    public function succeeded(string $id): void
    {
        $this->succeeded[] = $id;
    }

    public function failed(string $id, string $message): void
    {
        $this->failed[$id] = $message;
    }

    public function successCount(): int
    {
        return count($this->succeeded);
    }

    public function failureCount(): int
    {
        return count($this->failed);
    }

    /**
     * @return array<string, string>
     */
    public function failures(): array
    {
        return $this->failed;
    }

    public function summary(string $subject): string
    {
        return sprintf(
            '%s: %d erfolgreich, %d fehlgeschlagen.',
            $subject,
            $this->successCount(),
            $this->failureCount(),
        );
    }
}
