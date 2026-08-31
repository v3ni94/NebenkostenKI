<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Result;

use App\Domain\Calculation\OccupancyKind;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * Kostenanteil eines Leerstands oder eines nicht belegten Zeitraums.
 *
 * Diese Anteile werden dem Eigentümer zugerechnet und getrennt ausgewiesen.
 * Sie werden niemals auf Mieter umgelegt (Pflichtenheft Abschnitt 11.2:
 * "Leerstandskosten bleiben beim Eigentümer").
 */
final readonly class OwnerVacancyShare
{
    /**
     * @param  list<StatementLine>  $lines
     */
    public function __construct(
        public string $occupancyKey,
        public string $unitKey,
        public string $unitLabel,
        public OccupancyKind $kind,
        public DatePeriodRange $period,
        public array $lines,
        public Money $total,
    ) {}

    public function days(): int
    {
        return $this->period->days();
    }

    public function shareForCategory(string $categoryKey): Money
    {
        $sum = Money::zero();

        foreach ($this->lines as $line) {
            if ($line->categoryKey === $categoryKey) {
                $sum = $sum->plus($line->share);
            }
        }

        return $sum;
    }
}
