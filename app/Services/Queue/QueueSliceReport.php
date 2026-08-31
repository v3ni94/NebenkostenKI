<?php

declare(strict_types=1);

namespace App\Services\Queue;

/**
 * Ergebnis eines begrenzten Queue-Laufs.
 *
 * DATENSCHUTZ: Der Bericht enthaelt nur Zaehler und Fehlercodes, keine
 * Dokumentbezuege im Klartext und keine Inhalte.
 */
final class QueueSliceReport
{
    /**
     * @param  array<string, int>  $errorCodes  Fehlercode zu Anzahl
     */
    public function __construct(
        public readonly int $processed = 0,
        public readonly int $succeeded = 0,
        public readonly int $failed = 0,
        public readonly int $deadLettered = 0,
        public readonly int $reclaimed = 0,
        public readonly int $released = 0,
        public readonly array $errorCodes = [],
    ) {}

    public function summary(): string
    {
        return sprintf(
            'Verarbeitet: %d, erfolgreich: %d, fehlgeschlagen: %d, endgültig fehlgeschlagen: %d, '
            .'zurückgeholt: %d, zurückgestellt: %d.',
            $this->processed,
            $this->succeeded,
            $this->failed,
            $this->deadLettered,
            $this->reclaimed,
            $this->released,
        );
    }
}
