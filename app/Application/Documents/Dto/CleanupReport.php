<?php

declare(strict_types=1);

namespace App\Application\Documents\Dto;

/**
 * Ergebnis eines Cleanup- oder Wiederholungslaufs.
 *
 * DATENSCHUTZ: Nur Zaehler. Keine Dokumentbezeichnungen, keine Schluessel.
 */
final class CleanupReport
{
    public function __construct(
        public readonly int $inspected = 0,
        public readonly int $deleted = 0,
        public readonly int $failed = 0,
        public readonly int $alreadyDeleted = 0,
        public readonly int $cancelledJobs = 0,
    ) {}

    public function summary(): string
    {
        return sprintf(
            'Geprüft: %d, gelöscht: %d, fehlgeschlagen: %d, bereits gelöscht: %d, abgebrochene Teiljobs: %d.',
            $this->inspected,
            $this->deleted,
            $this->failed,
            $this->alreadyDeleted,
            $this->cancelledJobs,
        );
    }
}
