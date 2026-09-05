<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Ergebnis des Dokumentabgleichs nach Abschnitt 7.4.
 *
 * Die Matrix und die Befunde sind Vorschlaege. Ob eine Dublette vorliegt und
 * ob eine Abweichung die Finalisierung blockiert, entscheidet die
 * deterministische Regel-Engine (Grundsatz 1).
 */
final class ReconciliationResult
{
    public function __construct(
        public readonly ExtractionResult $extraction,
    ) {}

    public function status(): AiResultStatus
    {
        return $this->extraction->status;
    }

    public function metadata(): AiCallMetadata
    {
        return $this->extraction->metadata;
    }

    public function isValidated(): bool
    {
        return $this->extraction->isValidated();
    }

    /**
     * Zeilen der Reconciliation-Matrix, unveraendert aus den validierten
     * Extraktionsdaten.
     *
     * @return list<array<string, mixed>>
     */
    public function matrixRows(): array
    {
        return $this->rowsOf('matrix');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findings(): array
    {
        return $this->rowsOf('befunde');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOf(string $key): array
    {
        $rows = $this->extraction->data[$key] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $result[] = $row;
            }
        }

        return $result;
    }
}
