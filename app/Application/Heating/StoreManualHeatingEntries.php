<?php

declare(strict_types=1);

namespace App\Application\Heating;

use App\Application\Account\AuditRecorder;
use App\Application\Reconciliation\CategoryResolver;
use App\Application\Reconciliation\IssueRecorder;
use App\Application\Reconciliation\RuleCode;
use App\Domain\Calculation\Heating\ManualHeatingEntry;
use App\Domain\Calculation\Heating\ManualHeatingInput;
use App\Domain\Calculation\Heating\ManualHeatingReconciler;
use App\Domain\Calculation\Heating\ManualHeatingResult;
use App\Domain\Money\Money;
use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Enums\ApportionmentStatus;
use App\Enums\CostItemSource;
use App\Enums\CostItemStatus;
use App\Enums\HeatingSupplyCase;
use App\Enums\ValidationSeverity;
use App\Enums\ValueSource;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use App\Models\BillingRun;
use App\Models\CostCategory;
use App\Models\CostItem;
use App\Models\HeatingStatement;
use App\Models\HeatingStatementLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Uebernahme der manuell erfassten Heizkosten (Fall B).
 *
 * FACHLICHE FESTLEGUNG: Die Plattform rechnet die eingetragenen Betraege nicht
 * nach und verteilt sie nicht selbst. Sie uebernimmt sie unveraendert als
 * Direktzuordnung je Einheit, technisch auf demselben Weg wie Fall A: ein
 * Verteilerschluessel vom Typ Direktzuordnung mit Werten je Nutzungszeitraum.
 *
 * Bei Mieterwechsel innerhalb einer Einheit wird der Betrag der Einheit
 * zeitanteilig nach Nutzungstagen verteilt. Gerechnet wird ausschliesslich
 * ueber die vorhandene Domainlogik (ManualHeatingReconciler mit dem
 * Largest-Remainder-Verfahren), niemals ueber einen float-Zwischenschritt.
 *
 * DOPPELZAEHLUNG: Liegt fuer denselben Zeitraum zusaetzlich eine externe
 * Heizkostenabrechnung oder eine WEG-Summenposition vor, wird NICHT addiert.
 * Es entsteht eine Pruefaufgabe, und bis zur Entscheidung des Anwenders
 * werden keine Kostenpositionen aus der manuellen Erfassung erzeugt.
 */
final class StoreManualHeatingEntries
{
    public const string DECISION_MANUAL = 'MANUELL';

    public const string DECISION_EXTERNAL = 'EXTERN';

    private const string CATEGORY_HEATING = 'HEIZUNG';

    private const string CATEGORY_WARM_WATER = 'WARMWASSER';

    private const string AUDIT_ACTION = 'heizkosten.manuell.erfasst';

    public function __construct(
        private readonly ManualHeatingWorkspace $workspace,
        private readonly ManualHeatingReconciler $reconciler,
        private readonly ManualHeatingConflictScanner $conflicts,
        private readonly CategoryResolver $categories,
        private readonly IssueRecorder $issues,
        private readonly AuditRecorder $audit,
    ) {}

    public static function toleranceCent(): int
    {
        $value = config('smartabrechnen.tolerances.checksum_cent');

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * @param  array<string, array<string, string|null>>  $amountsByUnit  Einheit => Feld => Betrag in Euro
     */
    public function handle(
        BillingRun $billingRun,
        User $actor,
        array $amountsByUnit,
        ?string $declaredTotal = null,
        ?string $calculationOrigin = null,
        ?string $sourceDecision = null,
    ): ManualHeatingResult {
        $input = $this->buildInput($billingRun, $amountsByUnit, $declaredTotal, $calculationOrigin);
        $result = $this->reconciler->reconcile($input, Money::fromCents(self::toleranceCent()));
        $conflicts = $this->conflicts->conflictingSources($billingRun);
        $decision = $this->normalizeDecision($sourceDecision);

        DB::transaction(function () use ($billingRun, $input, $result, $conflicts, $decision): void {
            $statement = $this->writeStatement($billingRun, $input, $result, $decision);
            $this->writeLines($billingRun, $statement, $input);
            $this->clearManualCostItems($billingRun);

            if ($conflicts === [] || $decision === self::DECISION_MANUAL) {
                $this->writeCostItems($billingRun, $input);
            }

            $this->recordConflictIssue($billingRun, $statement, $conflicts, $decision);
        });

        $organizationId = $billingRun->getAttribute('organization_id');

        $this->audit->record(
            action: self::AUDIT_ACTION,
            subject: $billingRun,
            actor: $actor,
            organization: is_string($organizationId) ? $organizationId : null,
            metadata: [
                'einheiten' => count($input->entriesByUnit),
                'summe_cent' => $result->sumOfRecordedAmounts->cents,
                'pruefsumme_moeglich' => $result->checksumAvailable,
                'pruefsumme_eingehalten' => $result->withinTolerance,
                'quellenkonflikt' => $conflicts !== [],
            ],
        );

        return $result;
    }

    /**
     * @param  array<string, array<string, string|null>>  $amountsByUnit
     */
    private function buildInput(
        BillingRun $billingRun,
        array $amountsByUnit,
        ?string $declaredTotal,
        ?string $calculationOrigin,
    ): ManualHeatingInput {
        $entries = [];

        foreach ($this->workspace->units($billingRun) as $unit) {
            $unitId = (string) $unit->getKey();
            $values = $amountsByUnit[$unitId] ?? [];

            $entries[$unitId] = new ManualHeatingEntry(
                $unitId,
                (string) $unit->getAttribute('label'),
                EuroAmountInput::parseOrZero($values['heizung'] ?? null),
                EuroAmountInput::parseOrZero($values['warmwasser'] ?? null),
                EuroAmountInput::parseOrZero($values['co2_vermieter'] ?? null),
                EuroAmountInput::parseOrZero($values['co2_mieter'] ?? null),
                EuroAmountInput::parseOrZero($values['sonstige'] ?? null),
            );
        }

        $origin = $calculationOrigin === null ? null : trim($calculationOrigin);

        return new ManualHeatingInput(
            $this->workspace->period($billingRun),
            $entries,
            EuroAmountInput::parse($declaredTotal),
            $origin === '' ? null : $origin,
        );
    }

    private function writeStatement(
        BillingRun $billingRun,
        ManualHeatingInput $input,
        ManualHeatingResult $result,
        ?string $decision,
    ): HeatingStatement {
        $statement = $this->workspace->statement($billingRun) ?? new HeatingStatement;

        $co2Landlord = Money::zero();
        $co2Tenant = Money::zero();
        $other = Money::zero();
        $heating = Money::zero();
        $warmWater = Money::zero();

        foreach ($input->entriesByUnit as $entry) {
            $co2Landlord = $co2Landlord->plus($entry->co2Landlord);
            $co2Tenant = $co2Tenant->plus($entry->co2Tenant);
            $other = $other->plus($entry->other);
            $heating = $heating->plus($entry->heating);
            $warmWater = $warmWater->plus($entry->warmWater);
        }

        $statement->fill([
            'organization_id' => $billingRun->getAttribute('organization_id'),
            'billing_run_id' => $billingRun->getKey(),
            'document_id' => null,
            'provider_name' => null,
            'period_start' => $input->period->start,
            'period_end' => $input->period->end,
            'supply_case' => HeatingSupplyCase::ZENTRAL_OHNE_EXTERN,
            'manual_entry' => true,
            'calculation_origin' => $input->calculationOrigin,
            'total_cost_cent' => $input->declaredTotal?->cents,
            'heating_cost_cent' => $heating->cents,
            'warm_water_cost_cent' => $warmWater->cents,
            'co2_cost_cent' => $co2Landlord->plus($co2Tenant)->cents,
            'co2_landlord_cent' => $co2Landlord->cents,
            'co2_tenant_cent' => $co2Tenant->cents,
            'other_cost_cent' => $other->cents,
            'checksum_lines_total_cent' => $result->sumOfRecordedAmounts->cents,
            'checksum_difference_cent' => $result->difference?->cents,
            'checksum_ok' => $result->checksumAvailable ? $result->withinTolerance : null,
            'manual_source_decision' => $decision,
        ]);

        $statement->save();

        return $statement;
    }

    /**
     * Eine Zeile je Einheit und Nutzungszeitraum. Bei Mieterwechsel wird der
     * Betrag der Einheit zeitanteilig nach Nutzungstagen verteilt.
     */
    private function writeLines(BillingRun $billingRun, HeatingStatement $statement, ManualHeatingInput $input): void
    {
        HeatingStatementLine::query()
            ->where('heating_statement_id', $statement->getKey())
            ->delete();

        $period = $input->period;

        foreach ($this->workspace->units($billingRun) as $unit) {
            $unitId = (string) $unit->getKey();
            $entry = $input->entriesByUnit[$unitId] ?? null;

            if (! $entry instanceof ManualHeatingEntry || $entry->isEmpty()) {
                continue;
            }

            $occupancies = $this->workspace->occupancies($unit, $period);
            $days = [];

            foreach ($occupancies as $occupancy) {
                $days[$occupancy->tenancyId] = $occupancy->days;
            }

            $splits = [
                'share_heating_cent' => $this->reconciler->splitByUsageDays($entry->heating, $days),
                'share_warm_water_cent' => $this->reconciler->splitByUsageDays($entry->warmWater, $days),
                'share_co2_landlord_cent' => $this->reconciler->splitByUsageDays($entry->co2Landlord, $days),
                'share_co2_tenant_cent' => $this->reconciler->splitByUsageDays($entry->co2Tenant, $days),
                'share_other_cent' => $this->reconciler->splitByUsageDays($entry->other, $days),
            ];

            if ($days === []) {
                // Keine Nutzung im Abrechnungszeitraum: der Betrag bleibt der
                // Einheit zugeordnet und wird nicht auf Mieter verteilt.
                $this->line($billingRun, $statement, $unitId, (string) $unit->getAttribute('label'), null, [
                    'share_heating_cent' => $entry->heating->cents,
                    'share_warm_water_cent' => $entry->warmWater->cents,
                    'share_co2_landlord_cent' => $entry->co2Landlord->cents,
                    'share_co2_tenant_cent' => $entry->co2Tenant->cents,
                    'share_other_cent' => $entry->other->cents,
                ], null, null);

                continue;
            }

            foreach ($occupancies as $occupancy) {
                $values = [];

                foreach ($splits as $column => $shares) {
                    $share = $shares[$occupancy->tenancyId] ?? Money::zero();
                    $values[$column] = $share->cents;
                }

                $this->line(
                    $billingRun,
                    $statement,
                    $unitId,
                    (string) $unit->getAttribute('label'),
                    $occupancy->tenancyId,
                    $values,
                    $occupancy->days,
                    $occupancy->periodLabel,
                );
            }
        }
    }

    /**
     * @param  array<string, int>  $values
     */
    private function line(
        BillingRun $billingRun,
        HeatingStatement $statement,
        string $unitId,
        string $unitLabel,
        ?string $tenancyId,
        array $values,
        ?int $days,
        ?string $periodLabel,
    ): void {
        $tenantTotal = ($values['share_heating_cent'] ?? 0)
            + ($values['share_warm_water_cent'] ?? 0)
            + ($values['share_co2_tenant_cent'] ?? 0)
            + ($values['share_other_cent'] ?? 0);

        $line = new HeatingStatementLine;

        $line->fill(array_merge($values, [
            'organization_id' => $billingRun->getAttribute('organization_id'),
            'heating_statement_id' => $statement->getKey(),
            'unit_id' => $unitId,
            'tenancy_id' => $tenancyId,
            'unit_label' => mb_substr($unitLabel, 0, 120),
            'share_total_cent' => $tenantTotal,
            'usage_days' => $days,
            'usage_period_label' => $periodLabel === null ? null : mb_substr($periodLabel, 0, 60),
        ]));

        $line->save();
    }

    /**
     * Kostenpositionen und Verteilerschluessel der manuellen Erfassung.
     * Ein erneutes Speichern ersetzt sie vollstaendig.
     */
    private function clearManualCostItems(BillingRun $billingRun): void
    {
        $items = CostItem::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('source', CostItemSource::MANUELL->value)
            ->where('manual_heating_entry', true)
            ->get();

        foreach ($items as $item) {
            AllocationKey::query()
                ->where('cost_item_id', $item->getKey())
                ->delete();

            $item->delete();
        }
    }

    private function writeCostItems(BillingRun $billingRun, ManualHeatingInput $input): void
    {
        $heating = [];
        $warmWater = [];

        foreach ($input->entriesByUnit as $unitKey => $entry) {
            $heatingAmount = Money::sum($entry->heating, $entry->co2Tenant, $entry->other);

            if (! $heatingAmount->isZero()) {
                $heating[(string) $unitKey] = $heatingAmount;
            }

            if (! $entry->warmWater->isZero()) {
                $warmWater[(string) $unitKey] = $entry->warmWater;
            }
        }

        $this->writeCostItem(
            $billingRun,
            self::CATEGORY_HEATING,
            'Heizkosten, vom Vermieter ermittelt',
            $heating,
            true,
            false,
        );

        $this->writeCostItem(
            $billingRun,
            self::CATEGORY_WARM_WATER,
            'Warmwasserkosten, vom Vermieter ermittelt',
            $warmWater,
            true,
            true,
        );
    }

    /**
     * @param  array<string, Money>  $amountsByUnit
     */
    private function writeCostItem(
        BillingRun $billingRun,
        string $categoryCode,
        string $description,
        array $amountsByUnit,
        bool $isHeatingCost,
        bool $isWarmWaterCost,
    ): void {
        if ($amountsByUnit === []) {
            return;
        }

        $total = Money::zero();

        foreach ($amountsByUnit as $amount) {
            $total = $total->plus($amount);
        }

        $category = $this->categories->byCode($billingRun, $categoryCode);
        $period = $this->workspace->period($billingRun);

        $item = new CostItem;

        $item->fill([
            'organization_id' => $billingRun->getAttribute('organization_id'),
            'billing_run_id' => $billingRun->getKey(),
            'cost_category_id' => $category instanceof CostCategory ? $category->getKey() : null,
            'document_id' => null,
            'description' => mb_substr($description, 0, 190),
            'amount_cent' => $total->cents,
            'service_period_start' => $period->start,
            'service_period_end' => $period->end,
            'source' => CostItemSource::MANUELL,
            'status' => CostItemStatus::BESTAETIGT,
            'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
            'excluded_from_apportionment' => false,
            'is_heating_cost' => $isHeatingCost,
            'is_warm_water_cost' => $isWarmWaterCost,
            'manual_heating_entry' => true,
        ]);

        $item->save();

        $this->writeAllocationKey($billingRun, $item, $amountsByUnit);
    }

    /**
     * Direktzuordnung je Nutzungszeitraum, technisch derselbe Weg wie Fall A.
     *
     * @param  array<string, Money>  $amountsByUnit
     */
    private function writeAllocationKey(BillingRun $billingRun, CostItem $item, array $amountsByUnit): void
    {
        $period = $this->workspace->period($billingRun);
        $values = [];

        foreach ($this->workspace->units($billingRun) as $unit) {
            $unitId = (string) $unit->getKey();
            $amount = $amountsByUnit[$unitId] ?? null;

            if (! $amount instanceof Money) {
                continue;
            }

            $days = [];

            foreach ($this->workspace->occupancies($unit, $period) as $occupancy) {
                $days[$occupancy->tenancyId] = $occupancy->days;
            }

            if ($days === []) {
                continue;
            }

            foreach ($this->reconciler->splitByUsageDays($amount, $days) as $tenancyId => $share) {
                $values[(string) $tenancyId] = $share->cents;
            }
        }

        if ($values === []) {
            return;
        }

        /** @var AllocationKey $key */
        $key = AllocationKey::query()->create([
            'organization_id' => $billingRun->getAttribute('organization_id'),
            'billing_run_id' => $billingRun->getKey(),
            'cost_item_id' => $item->getKey(),
            'cost_category_id' => $item->getAttribute('cost_category_id'),
            'key_type' => AllocationKeyType::DIREKT,
            'source' => AllocationKeySource::MANUELL,
            'label' => 'Direktzuordnung, vom Vermieter ermittelte Heizkosten',
            'note' => 'Die Beträge wurden vom Vermieter erfasst und unverändert übernommen. Eine Prüfung der '
                .'Verteilung durch die Plattform erfolgt nicht.',
        ]);

        foreach ($values as $tenancyId => $cents) {
            AllocationKeyValue::query()->create([
                'organization_id' => $billingRun->getAttribute('organization_id'),
                'allocation_key_id' => $key->getKey(),
                'unit_id' => null,
                'tenancy_id' => $tenancyId,
                'numerator' => (string) $cents,
                'source' => ValueSource::MANUELL,
            ]);
        }
    }

    /**
     * @param  list<string>  $conflicts
     */
    private function recordConflictIssue(
        BillingRun $billingRun,
        HeatingStatement $statement,
        array $conflicts,
        ?string $decision,
    ): void {
        if ($conflicts === []) {
            return;
        }

        $this->issues->record(
            $billingRun,
            RuleCode::HEATING_DOUBLE_COUNT_PREVENTED,
            $decision === null ? ValidationSeverity::BLOCKER : ValidationSeverity::WARNUNG,
            'Heizkosten aus mehreren Quellen',
            sprintf(
                'Für den Abrechnungszeitraum liegen sowohl manuell erfasste Heizkosten als auch weitere Quellen '
                .'vor: %s. Die Beträge werden nicht addiert. Bitte entscheiden Sie, welche Quelle gilt. %s',
                implode('; ', $conflicts),
                $decision === null
                    ? 'Solange keine Entscheidung vorliegt, werden aus der manuellen Erfassung keine '
                        .'Kostenpositionen gebildet.'
                    : sprintf('Ihre Entscheidung: %s.', $decision === self::DECISION_MANUAL
                        ? 'Es gelten die manuell erfassten Beträge'
                        : 'Es gilt die externe Abrechnung beziehungsweise die WEG-Position')
            ),
            HeatingStatement::class,
            (string) $statement->getKey(),
            $decision === null,
        );
    }

    private function normalizeDecision(?string $decision): ?string
    {
        return match ($decision) {
            self::DECISION_MANUAL => self::DECISION_MANUAL,
            self::DECISION_EXTERNAL => self::DECISION_EXTERNAL,
            default => null,
        };
    }
}
