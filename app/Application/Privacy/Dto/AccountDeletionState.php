<?php

declare(strict_types=1);

namespace App\Application\Privacy\Dto;

use Illuminate\Support\Carbon;

/**
 * Zustand des Konto-Löschantrags eines Nutzers.
 *
 * Der Zustand wird nicht in einer eigenen Spalte gehalten, sondern aus dem
 * Revisionsprotokoll abgeleitet. Damit ist jeder Schritt des Verfahrens
 * belegbar und es kann kein Antrag stillschweigend verschwinden.
 */
final class AccountDeletionState
{
    private function __construct(
        public readonly bool $pending,
        public readonly ?Carbon $requestedAt,
        public readonly ?Carbon $dueAt,
        public readonly int $graceDays,
    ) {}

    public static function none(int $graceDays): self
    {
        return new self(false, null, null, $graceDays);
    }

    public static function pending(Carbon $requestedAt, Carbon $dueAt, int $graceDays): self
    {
        return new self(true, $requestedAt, $dueAt, $graceDays);
    }

    /**
     * Ist die Frist abgelaufen, sodass die endgültige Löschung ausgeführt wird?
     */
    public function isDue(?Carbon $now = null): bool
    {
        if (! $this->pending || $this->dueAt === null) {
            return false;
        }

        return $this->dueAt->lessThanOrEqualTo($now ?? Carbon::now());
    }

    /**
     * Verbleibende volle Tage bis zur endgültigen Löschung.
     */
    public function remainingDays(?Carbon $now = null): int
    {
        if (! $this->pending || $this->dueAt === null) {
            return 0;
        }

        $jetzt = $now ?? Carbon::now();

        if ($this->dueAt->lessThanOrEqualTo($jetzt)) {
            return 0;
        }

        return (int) ceil($jetzt->diffInHours($this->dueAt, false) / 24);
    }

    /**
     * Anzeigeformat TT.MM.JJJJ.
     */
    public function dueAtLabel(): string
    {
        return $this->dueAt?->timezone('Europe/Berlin')->format('d.m.Y') ?? '';
    }

    public function requestedAtLabel(): string
    {
        return $this->requestedAt?->timezone('Europe/Berlin')->format('d.m.Y') ?? '';
    }
}
