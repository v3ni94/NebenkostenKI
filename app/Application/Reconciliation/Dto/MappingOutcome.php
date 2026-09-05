<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Dto;

/**
 * Ergebnis der Zuordnung ausgelesener Inhaltsdaten zu Kostenpositionen.
 *
 * Es entstehen ausschliesslich Vorschlaege. Fehlende Pflichtangaben stehen in
 * missing und werden als Pruefaufgabe geschrieben.
 */
final readonly class MappingOutcome
{
    /**
     * @param  list<ProposedCostItem>  $proposals
     * @param  list<MissingRequirement>  $missing
     * @param  list<ExcludedPositionRow>  $excluded
     */
    public function __construct(
        public array $proposals = [],
        public array $missing = [],
        public array $excluded = [],
    ) {}

    public function totalProposedCent(): int
    {
        $sum = 0;

        foreach ($this->proposals as $proposal) {
            $sum += $proposal->amountCent;
        }

        return $sum;
    }

    public function totalExcludedCent(): int
    {
        $sum = 0;

        foreach ($this->excluded as $row) {
            $sum += $row->amountCent;
        }

        return $sum;
    }

    public function merge(self $other): self
    {
        return new self(
            array_values(array_merge($this->proposals, $other->proposals)),
            array_values(array_merge($this->missing, $other->missing)),
            array_values(array_merge($this->excluded, $other->excluded)),
        );
    }
}
