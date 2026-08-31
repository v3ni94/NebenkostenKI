<?php

declare(strict_types=1);

namespace App\Services\Queue;

/**
 * Exponentieller Backoff der datenbankgestuetzten Queue (ADR-006).
 *
 * Der naechste Versuch wird mit wachsendem Abstand eingeplant, damit ein
 * dauerhaft gestoerter Provider die Cron-Laeufe nicht blockiert. Die
 * Verzoegerung ist nach oben gedeckelt, damit ein Job nicht faktisch liegen
 * bleibt.
 *
 * Der Jitter ist standardmaessig abgeschaltet. Damit ist die Reihenfolge im
 * Test reproduzierbar; im Betrieb kann er zugeschaltet werden, um gleichzeitig
 * fehlgeschlagene Jobs zu entzerren.
 */
final class BackoffStrategy
{
    public function __construct(
        private readonly int $baseSeconds = 30,
        private readonly int $multiplier = 2,
        private readonly int $maxSeconds = 3600,
        private readonly int $jitterSeconds = 0,
    ) {}

    /**
     * Verzoegerung in Sekunden vor dem naechsten Versuch.
     *
     * @param  int  $attempt  Anzahl der bereits unternommenen Versuche, mindestens 1
     */
    public function delayFor(int $attempt): int
    {
        $normalized = max(1, $attempt);

        $delay = $this->baseSeconds * ($this->multiplier ** ($normalized - 1));

        if (! is_int($delay) || $delay > $this->maxSeconds) {
            $delay = $this->maxSeconds;
        }

        if ($this->jitterSeconds > 0) {
            $delay += random_int(0, $this->jitterSeconds);
        }

        return min($this->maxSeconds + $this->jitterSeconds, max(1, (int) $delay));
    }
}
