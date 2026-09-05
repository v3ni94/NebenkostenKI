<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Result;

use App\Domain\Support\EngineVersion;

/**
 * Gesamtergebnis eines Abrechnungslaufs.
 *
 * Enthält die Mieterabrechnungen, die Eigentümerübersicht, alle
 * Prüfergebnisse und die Version des Rechenwegs. Die Anwendungsschicht
 * entscheidet anhand von blocksFinalization(), ob eine Finalisierung
 * zulässig ist.
 */
final readonly class CalculationRunResult
{
    /**
     * @param  list<UnitStatementResult>  $statements
     * @param  list<CheckFinding>  $findings
     */
    public function __construct(
        public array $statements,
        public OwnerOverviewResult $ownerOverview,
        public array $findings,
        public string $engineVersion = EngineVersion::CURRENT,
    ) {}

    public function blocksFinalization(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->blocksFinalization()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<CheckFinding>
     */
    public function findingsWithSeverity(CheckSeverity $severity): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (CheckFinding $finding): bool => $finding->severity === $severity
        ));
    }

    /**
     * @return list<CheckFinding>
     */
    public function findingsWithCode(CheckCode $code): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (CheckFinding $finding): bool => $finding->code === $code
        ));
    }

    public function hasFinding(CheckCode $code): bool
    {
        return $this->findingsWithCode($code) !== [];
    }

    public function statement(string $occupancyKey): ?UnitStatementResult
    {
        foreach ($this->statements as $statement) {
            if ($statement->occupancyKey === $occupancyKey) {
                return $statement;
            }
        }

        return null;
    }

    /**
     * Anzahl der erzeugten Mieterabrechnungen; sie ist die Preiseinheit des
     * Geschäftsmodells (eine Einheit kann mehrere Abrechnungen erzeugen).
     */
    public function statementCount(): int
    {
        return count($this->statements);
    }
}
