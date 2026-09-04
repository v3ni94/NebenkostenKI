<?php

declare(strict_types=1);

namespace App\Application\Calculation;

use App\Application\BillingRun\BillingRunProgress;
use App\Application\Calculation\Dto\AssembledCalculationInput;
use App\Application\Calculation\Dto\CalculationOutcome;
use App\Domain\Calculation\Result\CalculationRunResult;
use App\Domain\Calculation\Result\StatementLine;
use App\Domain\Calculation\Result\UnitStatementResult;
use App\Domain\Calculation\StatementCalculator;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Support\EngineVersion;
use App\Enums\AllocationKeyType;
use App\Enums\CalculationSnapshotStatus;
use App\Enums\Paragraph35aType;
use App\Enums\StatementResultKind;
use App\Enums\UnitStatementStatus;
use App\Models\BillingRun;
use App\Models\CalculationSnapshot;
use App\Models\UnitStatement;
use App\Models\UnitStatementLine;
use App\Models\User;
use App\Rules\Engine\RuleContextFactory;
use App\Rules\Engine\RuleEngine;
use App\Rules\Engine\RuleReport;
use App\Rules\Engine\ValidationIssueWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Berechnet einen Abrechnungslauf und sichert den Berechnungsstand.
 *
 * ABLAUF
 *
 *  1. Eingabe aufbauen (BillingRunInputAssembler). Ein fehlender Pflichtwert
 *     bricht ab, es wird nichts geschätzt.
 *  2. Regel-Engine mit dem Regelstand des Abrechnungszeitraums ausführen und
 *     die Ergebnisse als Prüfaufgaben schreiben.
 *  3. Deterministische Berechnung ausführen.
 *  4. Calculation Snapshot mit vollständiger normalisierter Eingabe,
 *     Ergebnis, Domain-Version, Ruleset-Version und SHA-256 anlegen.
 *  5. Mieterabrechnungen und Rechenzeilen schreiben.
 *
 * UNVERÄNDERLICHKEIT (Masterprompt 11.5, ARCHITECTURE.md Abschnitt 6)
 *
 * Ein bezahlter, also gesperrter Snapshot wird niemals überschrieben. Eine
 * erneute Berechnung erzeugt eine neue Version; der bisherige Stand behält
 * seine Eingabe, sein Ergebnis, seinen Hash und seine Sperre und erhält
 * lediglich den Status ERSETZT samt Verweis auf den neuen Stand. Dasselbe gilt
 * für die Mieterabrechnungen.
 */
final class CalculateBillingRun
{
    public function __construct(
        private readonly BillingRunInputAssembler $assembler,
        private readonly SnapshotSerializer $serializer,
        private readonly StatementCalculator $calculator,
        private readonly RuleContextFactory $contexts,
        private readonly RuleEngine $rules,
        private readonly ValidationIssueWriter $issues,
        private readonly BillingRunProgress $progress,
    ) {}

    public function handle(BillingRun $billingRun, ?User $actor = null): CalculationOutcome
    {
        $assembled = $this->assembler->assemble($billingRun);

        $report = $this->rules->runForContext($this->contexts->fromBillingRun($billingRun));
        $this->issues->persist($billingRun, $report);

        $result = $this->calculator->calculate($assembled->input);

        $outcome = $this->persist($billingRun, $assembled, $result, $report, $actor);

        // Ein gesicherter Berechnungsstand liegt vor, deshalb CALCULATED. Der
        // Aufruf ist wirkungslos, wenn der Lauf bereits weiter oder bezahlt
        // ist; eine erneute Berechnung schaltet niemals zurueck.
        $this->progress->berechnet($billingRun, $actor);

        return $outcome;
    }

    /**
     * Schreibt Snapshot und Mieterabrechnungen in einer Transaktion.
     */
    private function persist(
        BillingRun $billingRun,
        AssembledCalculationInput $assembled,
        CalculationRunResult $result,
        RuleReport $report,
        ?User $actor,
    ): CalculationOutcome {
        $inputPayload = $this->serializer->input($assembled->input);
        $resultPayload = $this->serializer->result($result);

        /** @var array{0: CalculationSnapshot, 1: bool} $written */
        $written = DB::transaction(function () use (
            $billingRun,
            $assembled,
            $result,
            $report,
            $actor,
            $inputPayload,
            $resultPayload
        ): array {
            BillingRun::query()->whereKey($billingRun->getKey())->lockForUpdate()->first();

            $previous = CalculationSnapshot::query()
                ->where('billing_run_id', $billingRun->getKey())
                ->where('status', '!=', CalculationSnapshotStatus::ERSETZT->value)
                ->orderByDesc('version_number')
                ->first();

            $maxVersion = CalculationSnapshot::query()
                ->where('billing_run_id', $billingRun->getKey())
                ->max('version_number');

            $version = is_numeric($maxVersion) ? ((int) $maxVersion) + 1 : 1;

            $organizationId = $billingRun->getAttribute('organization_id');

            /** @var CalculationSnapshot $snapshot */
            $snapshot = CalculationSnapshot::query()->create([
                'organization_id' => $organizationId,
                'billing_run_id' => $billingRun->getKey(),
                'version_number' => $version,
                'input' => $inputPayload,
                'result' => $resultPayload,
                'domain_version' => EngineVersion::CURRENT,
                'ruleset_version' => $report->rulesetVersion,
                'hash' => $this->serializer->hash(
                    $inputPayload,
                    $resultPayload,
                    EngineVersion::CURRENT,
                    $report->rulesetVersion
                ),
                'status' => CalculationSnapshotStatus::BERECHNET,
                'statement_count' => $result->statementCount(),
                'total_apportionable_cent' => $result->ownerOverview->allocatedToTenantsTotal->cents,
                'total_prepayment_actual_cent' => $this->prepaymentTotal($result),
                'total_balance_cent' => $result->ownerOverview->tenantBalanceTotal()->cents,
                'created_by_user_id' => $actor?->getKey(),
            ]);

            $replacedPaid = false;

            if ($previous instanceof CalculationSnapshot) {
                $replacedPaid = $previous->isLocked();

                // Nur Status und Verweis werden gesetzt. Eingabe, Ergebnis,
                // Hash und Sperre des bisherigen Stands bleiben unveraendert.
                $previous->forceFill([
                    'status' => CalculationSnapshotStatus::ERSETZT,
                    'replaced_by_snapshot_id' => $snapshot->getKey(),
                ])->save();
            }

            $this->writeStatements($billingRun, $assembled, $result, $snapshot);

            $billingRun->forceFill([
                'active_calculation_snapshot_id' => $snapshot->getKey(),
                'statement_count' => $result->statementCount(),
            ])->save();

            return [$snapshot, $replacedPaid];
        });

        return new CalculationOutcome($written[0], $result, $report, $assembled, $written[1]);
    }

    private function prepaymentTotal(CalculationRunResult $result): int
    {
        $total = 0;

        foreach ($result->statements as $statement) {
            $total += $statement->prepaymentActual->cents;
        }

        return $total;
    }

    /**
     * Schreibt die Mieterabrechnungen. Bestehende Abrechnungen werden nicht
     * überschrieben, sondern als ERSETZT geführt.
     */
    private function writeStatements(
        BillingRun $billingRun,
        AssembledCalculationInput $assembled,
        CalculationRunResult $result,
        CalculationSnapshot $snapshot,
    ): void {
        $organizationId = $billingRun->getAttribute('organization_id');
        $periodDays = $assembled->input->billingPeriod->days();
        $sequence = 0;

        foreach ($result->statements as $statementResult) {
            $tenancyId = $assembled->tenancyId($statementResult->occupancyKey);
            $unitId = $assembled->unitId($statementResult->unitKey);

            if ($tenancyId === null || $unitId === null) {
                continue;
            }

            $sequence++;

            $existing = UnitStatement::query()
                ->where('billing_run_id', $billingRun->getKey())
                ->where('tenancy_id', $tenancyId)
                ->orderByDesc('version_number')
                ->first();

            $version = $existing instanceof UnitStatement
                ? ((int) $existing->getAttribute('version_number')) + 1
                : 1;

            /** @var UnitStatement $statement */
            $statement = UnitStatement::query()->create([
                'organization_id' => $organizationId,
                'billing_run_id' => $billingRun->getKey(),
                'tenancy_id' => $tenancyId,
                'unit_id' => $unitId,
                'calculation_snapshot_id' => $snapshot->getKey(),
                'sequence_number' => $sequence,
                'version_number' => $version,
                'usage_period_start' => $statementResult->usagePeriod->startIso(),
                'usage_period_end' => $statementResult->usagePeriod->endIso(),
                'days_used' => $statementResult->usageDays(),
                'period_days' => $periodDays,
                'total_apportionable_cent' => $statementResult->allocableTotal->cents,
                'total_heating_cent' => $this->heatingTotal($assembled, $statementResult),
                'total_excluded_cent' => 0,
                'prepayment_target_cent' => $statementResult->prepaymentTarget->cents,
                'prepayment_actual_cent' => $statementResult->prepaymentActual->cents,
                'balance_cent' => $statementResult->balance->cents,
                'rounding_adjustment_total_cent' => $statementResult->totalRoundingAdjustmentCent(),
                'paragraph_35a_household_cent' => $statementResult->taxBenefitHouseholdServices->cents,
                'paragraph_35a_craftsman_cent' => $statementResult->taxBenefitCraftsmanServices->cents,
                'result_kind' => $this->resultKind($statementResult),
                'status' => UnitStatementStatus::BERECHNET,
            ]);

            if ($existing instanceof UnitStatement) {
                $existing->forceFill([
                    'status' => UnitStatementStatus::ERSETZT,
                    'replaced_by_statement_id' => $statement->getKey(),
                ])->save();
            }

            $this->writeLines($assembled, $statementResult, $statement, $organizationId);
        }

        // Abrechnungen frueherer Staende, deren Mietverhaeltnis im neuen
        // Ergebnis nicht mehr vorkommt, wurden in der Schleife nicht ersetzt.
        // Sie gehoeren nicht zum aktiven Stand und gelten als ersetzt, sonst
        // zaehlt scopeCurrent sie weiter als gueltige Abrechnung.
        UnitStatement::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('calculation_snapshot_id', '!=', $snapshot->getKey())
            ->whereNotIn('status', [UnitStatementStatus::ERSETZT->value, UnitStatementStatus::FINAL->value])
            ->update(['status' => UnitStatementStatus::ERSETZT->value]);
    }

    private function heatingTotal(AssembledCalculationInput $assembled, UnitStatementResult $statement): int
    {
        $total = 0;

        foreach ($statement->lines as $line) {
            if ($assembled->isHeatingCategory($line->categoryKey)) {
                $total += $line->share->cents;
            }
        }

        return $total;
    }

    private function resultKind(UnitStatementResult $statement): StatementResultKind
    {
        if ($statement->isAdditionalPayment()) {
            return StatementResultKind::NACHZAHLUNG;
        }

        return $statement->isCredit() ? StatementResultKind::GUTHABEN : StatementResultKind::AUSGEGLICHEN;
    }

    private function writeLines(
        AssembledCalculationInput $assembled,
        UnitStatementResult $statementResult,
        UnitStatement $statement,
        mixed $organizationId,
    ): void {
        $sortOrder = 0;

        foreach ($statementResult->lines as $line) {
            $sortOrder++;

            UnitStatementLine::query()->create([
                'organization_id' => $organizationId,
                'unit_statement_id' => $statement->getKey(),
                'cost_category_id' => $assembled->costCategoryId($line->costItemKey),
                'category_label' => $line->categoryLabel,
                'total_cost_cent' => $line->totalCost->cents,
                'allocation_key_type' => $this->keyType($assembled, $line),
                'allocation_key_label' => $line->allocationKeyLabel,
                'numerator' => $this->numeric($line->numerator),
                'denominator' => $this->numeric($line->denominator),
                'time_factor' => $this->numeric($line->timeFactor->formattedFactor(8)) ?? '1',
                'share_cent' => $line->share->cents,
                'rounding_adjustment_cent' => $line->roundingAdjustmentCent,
                'is_heating_line' => $assembled->isHeatingCategory($line->categoryKey),
                'paragraph_35a_labor_cent' => $line->taxBenefitLaborShare?->cents,
                'paragraph_35a_type' => $this->benefitType($line->taxBenefitCategory),
                'sort_order' => $sortOrder,
                'note' => $line->calculationExplanation(),
            ]);
        }
    }

    private function keyType(AssembledCalculationInput $assembled, StatementLine $line): AllocationKeyType
    {
        $ref = null;

        foreach ($assembled->input->costItems as $item) {
            if ($item->costItemKey === $line->costItemKey) {
                $ref = $item->allocationKeyRef;

                break;
            }
        }

        $value = $ref === null ? null : ($assembled->allocationKeyTypeByRef[$ref] ?? null);

        return $value === null
            ? AllocationKeyType::WOHNFLAECHE
            : (AllocationKeyType::tryFrom($value) ?? AllocationKeyType::WOHNFLAECHE);
    }

    private function benefitType(TaxBenefitCategory $category): Paragraph35aType
    {
        return match ($category) {
            TaxBenefitCategory::NONE => Paragraph35aType::NONE,
            TaxBenefitCategory::HOUSEHOLD_SERVICE => Paragraph35aType::HAUSHALTSNAHE_DIENSTLEISTUNG,
            TaxBenefitCategory::CRAFTSMAN_SERVICE => Paragraph35aType::HANDWERKERLEISTUNG,
        };
    }

    /**
     * Zähler und Nenner werden als Zeichenkette gespeichert. Die deutsche
     * Anzeigeform der Domainschicht wird dafür in die technische Form
     * gebracht, ohne nach float zu casten (ADR-004).
     */
    private function numeric(string $formatted): ?string
    {
        $normalized = str_replace(['.', ' '], '', $formatted);
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? $normalized : null;
    }

    /**
     * Zeitpunkt der letzten Berechnung, für Anzeige und Vorschauprüfung.
     */
    public static function calculatedAt(CalculationSnapshot $snapshot): ?Carbon
    {
        $createdAt = $snapshot->getAttribute('created_at');

        return $createdAt instanceof Carbon ? $createdAt : null;
    }
}
