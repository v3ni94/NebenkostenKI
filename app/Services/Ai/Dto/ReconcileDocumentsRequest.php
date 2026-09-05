<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Anfrage zum Abgleich mehrerer Dokumente nach Abschnitt 7.4.
 *
 * Das Modell liefert Vergleichszeilen und Befunde. Es berechnet keine
 * Betraege und faellt keine Entscheidung. Ob eine Dublette vorliegt und ob
 * eine Abweichung die Finalisierung blockiert, entscheidet die
 * deterministische Regel-Engine (Grundsatz 1).
 */
final class ReconcileDocumentsRequest
{
    /**
     * @param  list<ReconciliationSubject>  $subjects
     */
    public function __construct(
        public readonly array $subjects,
        public readonly AiRequestContext $context,
        public readonly ?string $periodFrom = null,
        public readonly ?string $periodTo = null,
        public readonly int $toleranceCent = 0,
    ) {}
}
