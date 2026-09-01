<?php

declare(strict_types=1);

namespace App\Application\Review\Dto;

/**
 * Fortschritt der automatischen Analyse (Schritt 3).
 *
 * Die Angaben sind konkret und ohne technische Providernamen. Der normale
 * Nutzer soll den Stand ohne Vorkenntnisse verstehen.
 */
final readonly class AnalysisProgress
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public int $documentsTotal,
        public int $documentsEvaluated,
        public int $documentsFailed,
        public int $unitsRecognized,
        public int $costItemsAssigned,
        public int $openChecks,
        public int $blockingChecks,
        public bool $complete,
        public array $lines = [],
    ) {}

    public function percent(): int
    {
        if ($this->documentsTotal <= 0) {
            return 0;
        }

        return (int) min(100, max(0, (int) round(($this->documentsEvaluated / $this->documentsTotal) * 100)));
    }
}
