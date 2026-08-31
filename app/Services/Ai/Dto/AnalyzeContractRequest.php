<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Anfrage zur Analyse eines Mietvertrags oder Nachtrags.
 *
 * Vertraege sind komplexe Dokumente. Nach Abschnitt 13.8 wird hier das
 * leistungsfaehigere Analysemodell verwendet.
 */
final class AnalyzeContractRequest
{
    public function __construct(
        public readonly DocumentPayload $document,
        public readonly AiRequestContext $context,
        public readonly bool $isAmendment = false,
    ) {}
}
