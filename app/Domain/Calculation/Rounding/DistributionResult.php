<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Rounding;

use Brick\Math\BigRational;

/**
 * Ergebnis einer Verteilung mit Largest-Remainder-Verfahren.
 *
 * Die Summe aller Anteile entspricht exakt dem verteilten Gesamtbetrag.
 * Der Rundungsausgleich je Beteiligtem ist die Differenz zwischen dem
 * tatsächlich zugewiesenen Betrag und dem kaufmännisch gerundeten
 * Exaktbetrag; er wird in der Ergebniszeile als roundingAdjustmentCent
 * gespeichert.
 */
final readonly class DistributionResult
{
    /**
     * @param  array<string, int>  $amounts  zugewiesene ganzzahlige Einheiten (Cent bzw. skalierte Menge)
     * @param  array<string, int>  $roundingAdjustments  Ausgleich gegenüber kaufmännischer Rundung
     * @param  array<string, BigRational>  $exactAmounts  exakte, ungerundete Anteile
     */
    public function __construct(
        public int $total,
        public array $amounts,
        public array $roundingAdjustments,
        public array $exactAmounts,
    ) {}

    public function amountFor(string $participantKey): int
    {
        return $this->amounts[$participantKey] ?? 0;
    }

    public function adjustmentFor(string $participantKey): int
    {
        return $this->roundingAdjustments[$participantKey] ?? 0;
    }

    public function exactFor(string $participantKey): BigRational
    {
        return $this->exactAmounts[$participantKey] ?? BigRational::zero();
    }

    public function distributedTotal(): int
    {
        return array_sum($this->amounts);
    }

    /**
     * Prüfsumme: die Verteilung ist immer exakt.
     */
    public function isExact(): bool
    {
        return $this->distributedTotal() === $this->total;
    }

    /**
     * @return list<string>
     */
    public function participantKeys(): array
    {
        return array_map(static fn (int|string $key): string => (string) $key, array_keys($this->amounts));
    }
}
