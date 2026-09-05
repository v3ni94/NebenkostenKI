<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Result;

use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * Ergebnis eines Mietverhältnisses (eine Mieterabrechnung).
 *
 * Vorzeichenregel: balance() positiv = Nachzahlung des Mieters,
 * negativ = Guthaben des Mieters.
 *
 * assumptions enthält alle Annahmen und Kennzeichnungen in deutscher
 * Sprache, damit sie unverändert in das PDF und in den Prüfbericht
 * übernommen werden können.
 */
final readonly class UnitStatementResult
{
    /**
     * @param  list<StatementLine>  $lines
     * @param  list<string>  $assumptions
     * @param  list<CheckFinding>  $findings
     */
    public function __construct(
        public string $occupancyKey,
        public string $unitKey,
        public string $unitLabel,
        public string $tenantLabel,
        public DatePeriodRange $billingPeriod,
        public DatePeriodRange $usagePeriod,
        public array $lines,
        public Money $allocableTotal,
        public Money $prepaymentTarget,
        public Money $prepaymentActual,
        public bool $prepaymentAssumedFromTarget,
        public Money $balance,
        public Money $taxBenefitHouseholdServices,
        public Money $taxBenefitCraftsmanServices,
        public array $assumptions = [],
        public array $findings = [],
    ) {}

    public function usageDays(): int
    {
        return $this->usagePeriod->days();
    }

    /**
     * Nachzahlung des Mieters (positiver Betrag) oder 0,00 EUR.
     */
    public function additionalPayment(): Money
    {
        return $this->balance->isPositive() ? $this->balance : Money::zero();
    }

    /**
     * Guthaben des Mieters als positiver Betrag oder 0,00 EUR.
     */
    public function credit(): Money
    {
        return $this->balance->isNegative() ? $this->balance->negated() : Money::zero();
    }

    public function isAdditionalPayment(): bool
    {
        return $this->balance->isPositive();
    }

    public function isCredit(): bool
    {
        return $this->balance->isNegative();
    }

    /**
     * Summe der Rundungsausgleiche aller Zeilen.
     */
    public function totalRoundingAdjustmentCent(): int
    {
        $sum = 0;

        foreach ($this->lines as $line) {
            $sum += $line->roundingAdjustmentCent;
        }

        return $sum;
    }

    /**
     * Prüfsumme: die Summe der Zeilenanteile entspricht der ausgewiesenen
     * Summe der umlagefähigen Kosten.
     */
    public function linesMatchAllocableTotal(): bool
    {
        $sum = Money::zero();

        foreach ($this->lines as $line) {
            $sum = $sum->plus($line->share);
        }

        return $sum->equals($this->allocableTotal);
    }

    /**
     * Gesamter begünstigter Lohnanteil nach § 35a EStG.
     */
    public function taxBenefitTotal(): Money
    {
        return $this->taxBenefitHouseholdServices->plus($this->taxBenefitCraftsmanServices);
    }

    /**
     * @return list<StatementLine>
     */
    public function linesWithTaxBenefit(TaxBenefitCategory $category): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (StatementLine $line): bool => $line->taxBenefitCategory === $category
        ));
    }

    public function line(string $costItemKey): ?StatementLine
    {
        foreach ($this->lines as $line) {
            if ($line->costItemKey === $costItemKey) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @return list<StatementLine>
     */
    public function linesIncludedByOverride(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (StatementLine $line): bool => $line->includedByOverride
        ));
    }
}
