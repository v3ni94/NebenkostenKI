<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Models\ProcessingJob;

/**
 * Laufkontext eines Teiljobs.
 *
 * Der Handler kann damit das Lease verlaengern und die verbleibende Restzeit
 * des Cron-Laufs abfragen. Wird die Restzeit knapp, beendet der Handler seinen
 * Teilschritt und stellt den naechsten Teiljob ein, statt in einen
 * Zeitueberlauf des Webservers zu laufen.
 */
final class JobContext
{
    public function __construct(
        private readonly DatabaseJobQueue $queue,
        private readonly ProcessingJob $job,
        private readonly string $owner,
        private readonly float $deadline,
    ) {}

    /**
     * @return bool false, wenn das Lease inzwischen verloren ist. Der Handler
     *              bricht dann ohne Schreibzugriff ab.
     */
    public function heartbeat(): bool
    {
        return $this->queue->heartbeat($this->job, $this->owner);
    }

    public function secondsLeft(): float
    {
        return max(0.0, $this->deadline - microtime(true));
    }

    public function hasTimeLeft(float $required = 1.0): bool
    {
        return $this->secondsLeft() >= $required;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function attempt(): int
    {
        return (int) $this->job->getAttribute('attempts');
    }

    public function isLastAttempt(): bool
    {
        return $this->attempt() >= (int) $this->job->getAttribute('max_attempts');
    }
}
