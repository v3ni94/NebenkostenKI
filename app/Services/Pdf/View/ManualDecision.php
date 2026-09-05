<?php

declare(strict_types=1);

namespace App\Services\Pdf\View;

use DateTimeImmutable;

/**
 * Eine vom Nutzer getroffene manuelle Entscheidung (Abschnitt 14.2).
 *
 * Wird in der Eigentümerübersicht ausgewiesen, damit nachvollziehbar bleibt,
 * welche Prüfstelle der Nutzer wie entschieden hat.
 */
final readonly class ManualDecision
{
    public function __construct(
        public string $topic,
        public string $decision,
        public ?string $reason = null,
        public ?string $decidedBy = null,
        public ?DateTimeImmutable $decidedAt = null,
    ) {}
}
