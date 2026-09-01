<?php

declare(strict_types=1);

namespace App\Application\Calculation;

use App\Application\Calculation\Dto\PriceEstimate;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Models\BillingRun;
use App\Models\Tenancy;
use App\Models\Unit;
use Illuminate\Support\Carbon;

/**
 * Unverbindliche Preisschätzung vor der Vorschau (Masterprompt 1.3).
 *
 * Abrechnungseinheit für den Preis ist eine erzeugte Mieterabrechnung, nicht
 * die Wohnung. Bei einem Mieterwechsel entstehen für eine Einheit mehrere
 * Mieterabrechnungen.
 *
 * Die verbindliche Preisberechnung vor dem Checkout gehört nicht hierher. Diese
 * Klasse liefert ausschließlich die als unverbindlich gekennzeichnete
 * Schätzung.
 */
final class EstimatePrice
{
    public function forBillingRun(BillingRun $billingRun): PriceEstimate
    {
        return $this->forCount($this->expectedStatementCount($billingRun));
    }

    public function forCount(int $statementCount): PriceEstimate
    {
        $perStatement = Money::fromCents($this->configCent('per_statement_gross_cent', 2490));
        $base = Money::fromCents($this->configCent('base_gross_cent', 0));

        $total = Money::fromCents($perStatement->cents * max($statementCount, 0) + $base->cents);

        return new PriceEstimate(max($statementCount, 0), $perStatement, $base, $total);
    }

    /**
     * Voraussichtliche Anzahl der Mieterabrechnungen: jedes Mietverhältnis,
     * das im Abrechnungszeitraum bestand, erzeugt eine Abrechnung. Leerstände
     * erzeugen keine.
     */
    public function expectedStatementCount(BillingRun $billingRun): int
    {
        $snapshotCount = $billingRun->getAttribute('statement_count');

        if (is_int($snapshotCount) && $snapshotCount > 0) {
            return $snapshotCount;
        }

        $billingRun->loadMissing('property.units.tenancies');

        $period = new DatePeriodRange($billingRun->period_start, $billingRun->period_end);
        $count = 0;

        foreach ($billingRun->property->units as $unit) {
            $count += $this->tenancyCount($unit, $period);
        }

        return $count;
    }

    private function tenancyCount(Unit $unit, DatePeriodRange $period): int
    {
        $count = 0;

        foreach ($unit->tenancies as $tenancy) {
            if ($this->overlaps($tenancy, $period)) {
                $count++;
            }
        }

        return $count;
    }

    private function overlaps(Tenancy $tenancy, DatePeriodRange $period): bool
    {
        $end = $tenancy->ends_on instanceof Carbon ? $tenancy->ends_on : $period->end;

        if ($end < $tenancy->starts_on) {
            return false;
        }

        return $period->intersect(new DatePeriodRange($tenancy->starts_on, $end)) instanceof DatePeriodRange;
    }

    private function configCent(string $key, int $fallback): int
    {
        $value = config('smartabrechnen.pricing.'.$key);

        return is_int($value) ? $value : $fallback;
    }
}
