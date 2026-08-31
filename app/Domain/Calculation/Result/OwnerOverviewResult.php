<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Result;

use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * Eigentümerübersicht (Pflichtenheft Abschnitt 14.2).
 *
 * Enthält alle Ergebnisse je Mietverhältnis, die Leerstandsanteile zulasten
 * Eigentümer, die ausgeschlossenen Kosten, nicht verteilte Restanteile und
 * die Prüfsummen des Laufs.
 *
 * Prüfsumme: Summe der Mieteranteile + Leerstandsanteile + Restanteile muss
 * exakt der Summe der einbezogenen Kosten entsprechen.
 */
final readonly class OwnerOverviewResult
{
    /**
     * @param  list<UnitStatementResult>  $statements
     * @param  list<OwnerVacancyShare>  $vacancyShares
     * @param  list<ExcludedCost>  $excludedCosts
     * @param  list<ResidualShare>  $residualShares
     */
    public function __construct(
        public DatePeriodRange $billingPeriod,
        public string $propertyLabel,
        public array $statements,
        public array $vacancyShares,
        public array $excludedCosts,
        public array $residualShares,
        public Money $includedCostTotal,
        public Money $allocatedToTenantsTotal,
        public Money $vacancyTotal,
        public Money $residualTotal,
        public Money $excludedCostTotal,
    ) {}

    /**
     * Summe aller Kosten des Laufs, einbezogene und ausgeschlossene.
     */
    public function grossCostTotal(): Money
    {
        return $this->includedCostTotal->plus($this->excludedCostTotal);
    }

    /**
     * Kosten, die der Eigentümer trägt: Leerstand, Restanteile und
     * ausgeschlossene Positionen.
     */
    public function ownerBurdenTotal(): Money
    {
        return $this->vacancyTotal->plus($this->residualTotal)->plus($this->excludedCostTotal);
    }

    /**
     * Prüfsumme der Verteilung: Mieter + Leerstand + Restanteil = einbezogene Kosten.
     */
    public function isBalanced(): bool
    {
        return $this->allocatedToTenantsTotal
            ->plus($this->vacancyTotal)
            ->plus($this->residualTotal)
            ->equals($this->includedCostTotal);
    }

    public function checksumDifference(): Money
    {
        return $this->allocatedToTenantsTotal
            ->plus($this->vacancyTotal)
            ->plus($this->residualTotal)
            ->minus($this->includedCostTotal);
    }

    /**
     * Summe aller Mieternachzahlungen minus Mieterguthaben.
     */
    public function tenantBalanceTotal(): Money
    {
        $total = Money::zero();

        foreach ($this->statements as $statement) {
            $total = $total->plus($statement->balance);
        }

        return $total;
    }

    /**
     * @return list<OwnerVacancyShare>
     */
    public function vacancySharesForUnit(string $unitKey): array
    {
        return array_values(array_filter(
            $this->vacancyShares,
            static fn (OwnerVacancyShare $share): bool => $share->unitKey === $unitKey
        ));
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
}
