<?php

declare(strict_types=1);

namespace App\Rules\Context;

use App\Domain\Money\Money;

/**
 * Toleranzen der Pruefregeln.
 *
 * Die Werte stammen aus config('smartabrechnen.tolerances'). Sie werden in den
 * Kontext uebernommen, damit eine Regel keine Konfiguration liest und im Unit
 * Test ohne Framework pruefbar bleibt.
 */
final readonly class RuleTolerances
{
    public function __construct(
        public int $checksumCent = 100,
        public int $priorYearDeviationPercent = 30,
        public int $billingPeriodMonthsLimit = 12,
    ) {}

    /**
     * Uebernimmt die konfigurierten Toleranzen der Anwendung.
     */
    public static function fromConfig(): self
    {
        return new self(
            (int) config('smartabrechnen.tolerances.checksum_cent', 100),
            (int) config('smartabrechnen.tolerances.prior_year_deviation_percent', 30),
            (int) config('smartabrechnen.tolerances.billing_period_months_limit', 12),
        );
    }

    public function checksum(): Money
    {
        return Money::fromCents($this->checksumCent);
    }
}
