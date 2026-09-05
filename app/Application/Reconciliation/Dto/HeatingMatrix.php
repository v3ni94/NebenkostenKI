<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Dto;

/**
 * Reconciliation-Matrix der Heizkosten (Abschnitt 7.4).
 *
 * Liegt eine externe Heizkostenabrechnung vor, wird die Heizkostenposition der
 * WEG-Abrechnung nur als Vergleichssumme gefuehrt und nicht zusaetzlich
 * angesetzt. Eine Abweichung ueber der Toleranz blockiert die automatische
 * Finalisierung, bis der Nutzer sie erklaert oder korrigiert.
 *
 * Fall B ohne externen Abrechner erscheint als Quelle "manuell erfasst" mit
 * der Behandlung "Direktzuordnung". Liegen zusaetzlich eine externe Abrechnung
 * oder eine WEG-Summenposition vor, wird nicht addiert; der Anwender
 * entscheidet, welche Quelle gilt.
 */
final readonly class HeatingMatrix
{
    /**
     * @param  list<HeatingMatrixRow>  $rows
     * @param  list<MissingRequirement>  $missing
     */
    public function __construct(
        public array $rows = [],
        public array $missing = [],
        public bool $externalStatementPresent = false,
        public ?int $externalTotalCent = null,
        public ?int $lineSumCent = null,
        public ?int $differenceCent = null,
        public int $toleranceCent = 0,
        public bool $withinTolerance = true,
        public bool $blocksFinalization = false,
        public ?string $blockingExplanation = null,
        public bool $manualEntryPresent = false,
        public ?string $manualCalculationOrigin = null,
        public bool $manualSourceConflict = false,
        public ?string $manualConflictExplanation = null,
    ) {}

    public function hasRows(): bool
    {
        return $this->rows !== [];
    }
}
