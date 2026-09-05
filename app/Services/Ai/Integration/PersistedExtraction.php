<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

/**
 * Ergebnis der Persistenz eines Extraktionslaufs.
 *
 * Enthaelt ausschliesslich Zaehlwerte. Weder Werte noch Fundstellen noch
 * Providerantworten verlassen den Persister.
 */
final class PersistedExtraction
{
    public function __construct(
        public readonly int $persistedFieldCount,
        public readonly ?int $pageCount = null,
        public readonly int $reviewRequiredCount = 0,
        public readonly int $missingValueCount = 0,
        public readonly int $validationIssueCount = 0,
    ) {}
}
