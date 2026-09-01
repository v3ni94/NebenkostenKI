<?php

declare(strict_types=1);

namespace App\Application\Review\Dto;

/**
 * Kostengruppe der Pruefoberflaeche.
 *
 * Die Gruppe fasst alle Positionen einer Kategorie zu einer Zeile mit Summe
 * zusammen und ist auf die einzelnen Quelldokumente aufklappbar. Zwei
 * Gaertnerrechnungen erscheinen damit als eine Zeile "Gartenpflege" mit
 * Summe und lassen sich auf beide Belege aufklappen.
 */
final readonly class CostGroupView
{
    /**
     * @param  list<CostPositionView>  $positions
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $categoryCode,
        public int $sumCent,
        public string $sumLabel,
        public string $apportionmentStatus,
        public string $apportionmentLabel,
        public array $positions,
        public int $openCount,
        public int $duplicateCount,
        public bool $notAllocable,
        public bool $hasPeriodWarning,
    ) {}

    public function positionCount(): int
    {
        return count($this->positions);
    }
}
