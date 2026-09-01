<?php

declare(strict_types=1);

namespace App\Application\Calculation\Dto;

use App\Domain\Calculation\Result\CalculationRunResult;
use App\Models\CalculationSnapshot;
use App\Rules\Engine\RuleReport;

/**
 * Ergebnis einer Berechnung eines Abrechnungslaufs.
 *
 * Der Snapshot ist der verbindliche Berechnungsstand. Der Prüfbericht wird
 * getrennt geführt, weil er zusätzlich zu den Domainbefunden die versionierten
 * Regeln der Regel-Engine enthält.
 */
final readonly class CalculationOutcome
{
    public function __construct(
        public CalculationSnapshot $snapshot,
        public CalculationRunResult $result,
        public RuleReport $report,
        public AssembledCalculationInput $assembled,
        public bool $replacedPaidSnapshot = false,
    ) {}

    public function statementCount(): int
    {
        return $this->result->statementCount();
    }

    public function blocksFinalization(): bool
    {
        return $this->report->blocksFinalization() || $this->result->blocksFinalization();
    }
}
