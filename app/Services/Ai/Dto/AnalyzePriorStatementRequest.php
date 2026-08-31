<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Anfrage zur Analyse einer Vorjahres-Betriebskostenabrechnung.
 *
 * Vorjahreswerte dienen ausschliesslich dem Vergleich und werden niemals als
 * neue Kosten uebernommen (Abschnitt 8.3).
 */
final class AnalyzePriorStatementRequest
{
    public function __construct(
        public readonly DocumentPayload $document,
        public readonly AiRequestContext $context,
        public readonly ?string $currentPeriodFrom = null,
        public readonly ?string $currentPeriodTo = null,
    ) {}
}
