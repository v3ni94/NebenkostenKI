<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use App\Domain\Calculation\Check\PreviousYearCategoryAmount;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Enums\AllocationKeyType;
use App\Enums\CostItemStatus;
use App\Enums\DocumentRelationType;
use App\Enums\DocumentType;
use App\Enums\PaymentStatus;
use App\Models\AllocationKey;
use App\Models\BillingRun;
use App\Models\CalculationSnapshot;
use App\Models\CostItem;
use App\Models\DocumentRelation;
use App\Models\HeatingStatement;
use App\Models\Tenancy;
use App\Rules\Context\RuleAllocationKey;
use App\Rules\Context\RuleCategoryChecksum;
use App\Rules\Context\RuleContext;
use App\Rules\Context\RuleCostItem;
use App\Rules\Context\RuleEnvironment;
use App\Rules\Context\RuleFinalizationState;
use App\Rules\Context\RuleHausgeldChecksum;
use App\Rules\Context\RuleHeatingStatement;
use App\Rules\Context\RulePrepayment;
use App\Rules\Context\RuleSupplierHistory;
use App\Rules\Context\RuleTenancy;
use App\Rules\Context\RuleTolerances;
use App\Rules\Context\RuleUnit;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Aufbau des Regelkontexts aus den Modellen eines Abrechnungslaufs.
 *
 * Die Factory ist die Bruecke zwischen Persistenz und Regel-Engine. Alle
 * Schluessel im Kontext sind die Datenbankschluessel der Modelle, damit ein
 * Pruefergebnis unmittelbar auf die betroffene Entitaet verweist.
 *
 * Pruefsummen gegen Belege, Hausgeldanteile und Vorjahreswerte werden von der
 * Anwendungsschicht beigesteuert, weil sie aus der Extraktion stammen. Sie
 * koennen ueber die optionalen Parameter uebergeben werden.
 */
final class RuleContextFactory
{
    /**
     * @param  list<RuleCategoryChecksum>  $categoryChecksums
     * @param  list<RuleHausgeldChecksum>  $hausgeldChecksums
     * @param  list<PreviousYearCategoryAmount>  $previousYearCategories
     */
    public function fromBillingRun(
        BillingRun $billingRun,
        array $categoryChecksums = [],
        array $hausgeldChecksums = [],
        array $previousYearCategories = [],
        ?Money $singleAmountAttentionThreshold = null,
    ): RuleContext {
        $billingRun->loadMissing([
            'costItems.costCategory',
            'costItems.document',
            'allocationKeys.values',
            'heatingStatements.lines',
            'prepayments',
            'property.units.tenancies',
            'payments',
        ]);

        $period = new DatePeriodRange($billingRun->period_start, $billingRun->period_end);

        return new RuleContext(
            (string) $billingRun->getKey(),
            $period,
            $this->today(),
            RuleTolerances::fromConfig(),
            $this->costItems($billingRun),
            $categoryChecksums,
            $hausgeldChecksums,
            $this->heatingStatements($billingRun),
            $this->allocationKeys($billingRun),
            $this->units($billingRun),
            $this->tenancies($billingRun, $period),
            $this->prepayments($billingRun),
            $previousYearCategories,
            $this->supplierHistory($billingRun, $singleAmountAttentionThreshold),
            $this->finalizationState($billingRun),
            RuleEnvironment::fromConfig(),
        );
    }

    /**
     * @return list<RuleCostItem>
     */
    private function costItems(BillingRun $billingRun): array
    {
        $relatedInvoices = $this->creditNoteInvoiceMap($billingRun);
        $items = [];

        foreach ($billingRun->costItems as $item) {
            if ($item->status === CostItemStatus::VERWORFEN) {
                continue;
            }

            $category = $item->costCategory;
            $document = $item->document;
            $isCreditNote = $item->amount_cent < 0
                || ($document !== null && $document->document_type === DocumentType::GUTSCHRIFT);

            $items[] = new RuleCostItem(
                (string) $item->getKey(),
                $item->description,
                $category !== null ? (string) $category->getKey() : 'OHNE_KATEGORIE',
                $category !== null ? $category->name : 'ohne Kostenart',
                Money::fromCents($item->amount_cent),
                $this->servicePeriod($item),
                $item->supplier_name,
                $item->invoice_number,
                $item->document_date?->format('Y-m-d'),
                $document?->fingerprint_hmac,
                $item->apportionment_status,
                $item->excluded_from_apportionment,
                $item->apportionment_override_reason,
                $isCreditNote,
                $relatedInvoices[(string) $item->getKey()] ?? null,
                $item->paragraph_35a_type,
                $item->labor_share_cent,
                $category?->code === 'GRUNDSTEUER',
                $category?->code === 'SONSTIGE_BETRIEBSKOSTEN',
                $item->confirmed_at !== null,
            );
        }

        return $items;
    }

    /**
     * Zuordnung Gutschrift zu Rechnung ueber die Dokumentbeziehungen.
     *
     * @return array<string, string>
     */
    private function creditNoteInvoiceMap(BillingRun $billingRun): array
    {
        $itemsByDocument = [];

        foreach ($billingRun->costItems as $item) {
            if ($item->document_id === null) {
                continue;
            }

            $itemsByDocument[$item->document_id][] = (string) $item->getKey();
        }

        $relations = DocumentRelation::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('relation_type', DocumentRelationType::GUTSCHRIFT_ZU_RECHNUNG->value)
            ->get();

        $map = [];

        foreach ($relations as $relation) {
            $creditItems = $itemsByDocument[$relation->from_document_id] ?? [];
            $invoiceItems = $itemsByDocument[$relation->to_document_id] ?? [];

            if ($invoiceItems === []) {
                continue;
            }

            foreach ($creditItems as $creditItemKey) {
                $map[$creditItemKey] = $invoiceItems[0];
            }
        }

        return $map;
    }

    private function servicePeriod(CostItem $item): ?DatePeriodRange
    {
        if (! $item->service_period_start instanceof Carbon || ! $item->service_period_end instanceof Carbon) {
            return null;
        }

        return new DatePeriodRange($item->service_period_start, $item->service_period_end);
    }

    /**
     * @return list<RuleHeatingStatement>
     */
    private function heatingStatements(BillingRun $billingRun): array
    {
        $statements = [];

        foreach ($billingRun->heatingStatements as $statement) {
            $statements[] = new RuleHeatingStatement(
                (string) $statement->getKey(),
                $statement->supply_case,
                new DatePeriodRange($statement->period_start, $statement->period_end),
                $statement->provider_name,
                $this->optionalMoney($statement->total_cost_cent),
                $this->heatingLineAmounts($statement),
                $statement->co2_share_status,
                $this->optionalMoney($statement->basic_cost_cent),
                $this->optionalMoney($statement->consumption_cost_cent),
                $this->hasConsumptionValues($statement),
                null,
                $this->optionalMoney($statement->operating_current_cent),
                $this->optionalMoney($statement->warm_water_cost_cent),
                $this->optionalMoney($statement->co2_cost_cent),
            );
        }

        return $statements;
    }

    /**
     * @return array<string, Money>
     */
    private function heatingLineAmounts(HeatingStatement $statement): array
    {
        $amounts = [];

        foreach ($statement->lines as $line) {
            if ($line->share_total_cent === null) {
                continue;
            }

            $amounts[(string) $line->getKey()] = Money::fromCents($line->share_total_cent);
        }

        return $amounts;
    }

    private function hasConsumptionValues(HeatingStatement $statement): bool
    {
        if ($statement->lines->isEmpty()) {
            return false;
        }

        foreach ($statement->lines as $line) {
            if ($line->consumption === null || trim($line->consumption) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<RuleAllocationKey>
     */
    private function allocationKeys(BillingRun $billingRun): array
    {
        $keys = [];

        foreach ($billingRun->allocationKeys as $key) {
            $keys[] = new RuleAllocationKey(
                (string) $key->getKey(),
                $key->label ?? $key->key_type->label(),
                $key->key_type->value,
                $key->denominator,
                $this->numerators($key),
            );
        }

        return $keys;
    }

    /**
     * @return array<string, string|null>
     */
    private function numerators(AllocationKey $key): array
    {
        $numerators = [];

        foreach ($key->values as $value) {
            $reference = $value->unit_id ?? $value->tenancy_id;

            if ($reference === null) {
                continue;
            }

            $numerators[$reference] = $value->numerator;
        }

        return $numerators;
    }

    /**
     * @return list<RuleUnit>
     */
    private function units(BillingRun $billingRun): array
    {
        $requiresHeatedArea = $this->usesKeyType($billingRun, AllocationKeyType::BEHEIZTE_WOHNFLAECHE);
        $requiresShare = $this->usesKeyType($billingRun, AllocationKeyType::MEA);
        $units = [];

        foreach ($billingRun->property->units as $unit) {
            $units[] = new RuleUnit(
                (string) $unit->getKey(),
                $unit->label,
                $unit->living_area_sqm,
                $unit->heated_area_sqm,
                $unit->mea,
                $requiresHeatedArea,
                $requiresShare,
            );
        }

        return $units;
    }

    private function usesKeyType(BillingRun $billingRun, AllocationKeyType $type): bool
    {
        foreach ($billingRun->allocationKeys as $key) {
            if ($key->key_type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<RuleTenancy>
     */
    private function tenancies(BillingRun $billingRun, DatePeriodRange $billingPeriod): array
    {
        $tenancies = [];

        foreach ($billingRun->property->units as $unit) {
            foreach ($unit->tenancies as $tenancy) {
                $period = $this->tenancyPeriod($tenancy, $billingPeriod);

                if (! $period instanceof DatePeriodRange) {
                    continue;
                }

                $tenancies[] = new RuleTenancy(
                    (string) $tenancy->getKey(),
                    (string) $unit->getKey(),
                    $tenancy->tenant_display_name,
                    $period,
                    $this->hasMovedOut($tenancy, $billingPeriod),
                    $this->hasDeliveryAddress($tenancy),
                    $tenancy->other_operating_costs_agreed,
                );
            }
        }

        return $tenancies;
    }

    /**
     * Nutzungszeitraum innerhalb des Abrechnungszeitraums.
     */
    private function tenancyPeriod(Tenancy $tenancy, DatePeriodRange $billingPeriod): ?DatePeriodRange
    {
        $end = $tenancy->ends_on instanceof Carbon ? $tenancy->ends_on : $billingPeriod->end;
        $start = $tenancy->starts_on;

        if ($end < $start) {
            return null;
        }

        return (new DatePeriodRange($start, $end))->intersect($billingPeriod);
    }

    private function hasMovedOut(Tenancy $tenancy, DatePeriodRange $billingPeriod): bool
    {
        return $tenancy->ends_on instanceof Carbon
            && $billingPeriod->contains($tenancy->ends_on);
    }

    private function hasDeliveryAddress(Tenancy $tenancy): bool
    {
        return $tenancy->delivery_address_line !== null
            && trim($tenancy->delivery_address_line) !== ''
            && $tenancy->delivery_postal_code !== null
            && trim($tenancy->delivery_postal_code) !== ''
            && $tenancy->delivery_city !== null
            && trim($tenancy->delivery_city) !== '';
    }

    /**
     * @return list<RulePrepayment>
     */
    private function prepayments(BillingRun $billingRun): array
    {
        $prepayments = [];

        foreach ($billingRun->prepayments as $prepayment) {
            $prepayments[] = new RulePrepayment(
                (string) $prepayment->getKey(),
                $prepayment->tenancy_id,
                new DatePeriodRange($prepayment->period_start, $prepayment->period_end),
                Money::fromCents($prepayment->target_cent),
                $this->optionalMoney($prepayment->actual_cent),
                $prepayment->assumed_equal_to_target,
            );
        }

        return $prepayments;
    }

    private function supplierHistory(BillingRun $billingRun, ?Money $threshold): RuleSupplierHistory
    {
        $previous = $billingRun->previousBillingRun;

        if ($previous === null) {
            return new RuleSupplierHistory([], $threshold, false);
        }

        $suppliers = CostItem::query()
            ->where('billing_run_id', $previous->getKey())
            ->whereNotNull('supplier_name')
            ->pluck('supplier_name')
            ->map(static fn (mixed $name): string => is_string($name) ? $name : '')
            ->filter(static fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();

        /** @var list<string> $suppliers */
        return new RuleSupplierHistory($suppliers, $threshold, true);
    }

    private function finalizationState(BillingRun $billingRun): RuleFinalizationState
    {
        $paid = null;

        foreach ($billingRun->payments as $payment) {
            if ($payment->status === PaymentStatus::BEZAHLT) {
                $paid = Money::fromCents($payment->amount_cent);

                break;
            }
        }

        // Ein gesperrter Calculation Snapshot ist ein bereits finalisierter
        // Berechnungsstand. Er wird niemals ueberschrieben.
        $finalizedCount = CalculationSnapshot::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->whereNotNull('locked_at')
            ->count();

        return new RuleFinalizationState(
            $finalizedCount,
            $this->optionalMoney($billingRun->price_total_gross_cent),
            $paid,
        );
    }

    private function optionalMoney(?int $cents): ?Money
    {
        return $cents === null ? null : Money::fromCents($cents);
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(Carbon::now()->format('Y-m-d').' 00:00:00', new DateTimeZone('UTC'));
    }
}
