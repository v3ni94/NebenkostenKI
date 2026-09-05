<?php

declare(strict_types=1);

namespace App\Application\Heating;

use App\Application\Heating\Dto\ManualHeatingOccupancy;
use App\Application\Heating\Dto\ManualHeatingUnitRow;
use App\Domain\Calculation\Heating\ManualHeatingEntry;
use App\Domain\Calculation\Heating\ManualHeatingInput;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Enums\HeatingSupplyCase;
use App\Models\BillingRun;
use App\Models\HeatingStatement;
use App\Models\HeatingStatementLine;
use App\Models\Tenancy;
use App\Models\Unit;
use Illuminate\Support\Carbon;

/**
 * Lesende Sicht auf die manuelle Heizkostenerfassung (Fall B).
 *
 * Stellt die Einheiten mit ihren Nutzungszeitraeumen und den bereits erfassten
 * Betraegen bereit. Diese Klasse rechnet nichts und veraendert nichts.
 */
final class ManualHeatingWorkspace
{
    /**
     * Bereits erfasste Angaben des Laufs, sofern vorhanden.
     */
    public function statement(BillingRun $billingRun): ?HeatingStatement
    {
        $statement = HeatingStatement::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('supply_case', HeatingSupplyCase::ZENTRAL_OHNE_EXTERN->value)
            ->where('manual_entry', true)
            ->orderBy('created_at')
            ->first();

        return $statement instanceof HeatingStatement ? $statement : null;
    }

    /**
     * Eine Zeile je Einheit des Objekts.
     *
     * @return list<ManualHeatingUnitRow>
     */
    public function rows(BillingRun $billingRun): array
    {
        $period = $this->period($billingRun);
        $statement = $this->statement($billingRun);
        $recorded = $this->recordedAmounts($statement);
        $rows = [];

        foreach ($this->units($billingRun) as $unit) {
            $unitId = (string) $unit->getKey();
            $amounts = $recorded[$unitId] ?? null;

            $rows[] = new ManualHeatingUnitRow(
                $unitId,
                (string) $unit->getAttribute('label'),
                $this->occupancies($unit, $period),
                $amounts['heizung'] ?? null,
                $amounts['warmwasser'] ?? null,
                $amounts['co2_vermieter'] ?? null,
                $amounts['co2_mieter'] ?? null,
                $amounts['sonstige'] ?? null,
            );
        }

        return $rows;
    }

    /**
     * Fachliche Eingabestruktur der Domain aus den bereits erfassten Werten.
     */
    public function input(BillingRun $billingRun): ManualHeatingInput
    {
        $statement = $this->statement($billingRun);
        $entries = [];

        foreach ($this->rows($billingRun) as $row) {
            $entries[$row->unitId] = new ManualHeatingEntry(
                $row->unitId,
                $row->unitLabel,
                $row->heating ?? Money::zero(),
                $row->warmWater ?? Money::zero(),
                $row->co2Landlord ?? Money::zero(),
                $row->co2Tenant ?? Money::zero(),
                $row->other ?? Money::zero(),
            );
        }

        $total = $statement?->getAttribute('total_cost_cent');
        $origin = $statement?->getAttribute('calculation_origin');

        return new ManualHeatingInput(
            $this->period($billingRun),
            $entries,
            is_numeric($total) ? Money::fromCents((int) $total) : null,
            is_string($origin) && $origin !== '' ? $origin : null,
        );
    }

    /**
     * Einheiten des Objekts, aufsteigend nach Bezeichnung.
     *
     * @return list<Unit>
     */
    public function units(BillingRun $billingRun): array
    {
        $units = Unit::query()
            ->where('property_id', $billingRun->getAttribute('property_id'))
            ->with(['tenancies'])
            ->orderBy('label')
            ->get()
            ->all();

        return array_values($units);
    }

    public function period(BillingRun $billingRun): DatePeriodRange
    {
        $start = $billingRun->getAttribute('period_start');
        $end = $billingRun->getAttribute('period_end');

        return new DatePeriodRange(
            $start instanceof Carbon ? $start->toDateTimeImmutable() : Carbon::now()->startOfYear()->toDateTimeImmutable(),
            $end instanceof Carbon ? $end->toDateTimeImmutable() : Carbon::now()->endOfYear()->toDateTimeImmutable(),
        );
    }

    /**
     * Nutzungszeitraeume einer Einheit im Abrechnungszeitraum.
     *
     * @return list<ManualHeatingOccupancy>
     */
    public function occupancies(Unit $unit, DatePeriodRange $period): array
    {
        $occupancies = [];

        foreach ($unit->tenancies as $tenancy) {
            $range = $this->tenancyPeriod($tenancy, $period);

            if (! $range instanceof DatePeriodRange) {
                continue;
            }

            $clipped = $period->intersect($range);

            if (! $clipped instanceof DatePeriodRange) {
                continue;
            }

            $occupancies[] = new ManualHeatingOccupancy(
                (string) $tenancy->getKey(),
                (string) ($tenancy->getAttribute('tenant_display_name') ?? 'Mietverhältnis'),
                $clipped->days(),
                sprintf(
                    '%s bis %s',
                    $clipped->start->format('d.m.Y'),
                    $clipped->end->format('d.m.Y')
                ),
            );
        }

        return $occupancies;
    }

    private function tenancyPeriod(Tenancy $tenancy, DatePeriodRange $period): ?DatePeriodRange
    {
        $start = $tenancy->getAttribute('starts_on');
        $end = $tenancy->getAttribute('ends_on');

        if (! $start instanceof Carbon) {
            return null;
        }

        $endDate = $end instanceof Carbon ? $end : Carbon::instance($period->end);

        if ($start->gt($endDate)) {
            return null;
        }

        return new DatePeriodRange($start->toDateTimeImmutable(), $endDate->toDateTimeImmutable());
    }

    /**
     * Bereits erfasste Betraege je Einheit, aus den Zeilen zusammengefasst.
     *
     * @return array<string, array<string, Money>>
     */
    private function recordedAmounts(?HeatingStatement $statement): array
    {
        if (! $statement instanceof HeatingStatement) {
            return [];
        }

        $amounts = [];

        $lines = HeatingStatementLine::query()
            ->where('heating_statement_id', $statement->getKey())
            ->get();

        foreach ($lines as $line) {
            $unitId = $line->getAttribute('unit_id');

            if (! is_string($unitId) || $unitId === '') {
                continue;
            }

            $current = $amounts[$unitId] ?? [
                'heizung' => Money::zero(),
                'warmwasser' => Money::zero(),
                'co2_vermieter' => Money::zero(),
                'co2_mieter' => Money::zero(),
                'sonstige' => Money::zero(),
            ];

            $amounts[$unitId] = [
                'heizung' => $current['heizung']->plus($this->money($line, 'share_heating_cent')),
                'warmwasser' => $current['warmwasser']->plus($this->money($line, 'share_warm_water_cent')),
                'co2_vermieter' => $current['co2_vermieter']->plus($this->money($line, 'share_co2_landlord_cent')),
                'co2_mieter' => $current['co2_mieter']->plus($this->money($line, 'share_co2_tenant_cent')),
                'sonstige' => $current['sonstige']->plus($this->money($line, 'share_other_cent')),
            ];
        }

        return $amounts;
    }

    private function money(HeatingStatementLine $line, string $attribute): Money
    {
        $value = $line->getAttribute($attribute);

        return is_numeric($value) ? Money::fromCents((int) $value) : Money::zero();
    }
}
