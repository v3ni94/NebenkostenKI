<?php

declare(strict_types=1);

namespace App\Application\Calculation;

use App\Domain\Allocation\AllocationKey as DomainAllocationKey;
use App\Domain\Allocation\ConsumptionKey;
use App\Domain\Allocation\CoOwnershipShareKey;
use App\Domain\Allocation\DirectAssignmentKey;
use App\Domain\Allocation\HeatedLivingAreaKey;
use App\Domain\Allocation\IndividualKey;
use App\Domain\Allocation\LivingAreaKey;
use App\Domain\Allocation\PersonCountKey;
use App\Domain\Allocation\PersonDaysKey;
use App\Domain\Allocation\PersonDaysSegment;
use App\Domain\Allocation\UnitCountKey;
use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Dto\OccupancyInput;
use App\Domain\Calculation\Dto\PrepaymentInput;
use App\Domain\Calculation\Dto\StatementCalculationInput;
use App\Domain\Calculation\Dto\UnitInput;
use App\Domain\Calculation\OccupancyKind;
use App\Domain\Calculation\Result\CalculationRunResult;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Calculation\Result\StatementLine;
use App\Domain\Calculation\Result\UnitStatementResult;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * Normalisierung der Eingabe und des Ergebnisses eines Abrechnungslaufs.
 *
 * Der Calculation Snapshot muss den Berechnungsstand vollständig und
 * reproduzierbar tragen (ARCHITECTURE.md Abschnitt 6). Die Serialisierung ist
 * deshalb verlustfrei: aus der gespeicherten Eingabe wird über hydrate()
 * dieselbe Domaineingabe erzeugt, die dieselben Ergebnisse liefert.
 *
 * Dezimalwerte werden ausschließlich als Zeichenketten geführt, Geldbeträge
 * als Integer in Cent. Es entsteht an keiner Stelle ein binärer Float.
 *
 * Die Schlüssel sind bewusst sortiert, damit der Hash über den Snapshot
 * stabil ist.
 */
final class SnapshotSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function input(StatementCalculationInput $input): array
    {
        return [
            'schema' => 1,
            'propertyLabel' => $input->propertyLabel,
            'billingPeriod' => $this->period($input->billingPeriod),
            'units' => array_map(fn (UnitInput $unit): array => $this->unit($unit), $input->units),
            'occupancies' => array_map(fn (OccupancyInput $o): array => $this->occupancy($o), $input->occupancies),
            'costItems' => array_map(fn (CostItemInput $i): array => $this->costItem($i), $input->costItems),
            'allocationKeys' => $this->allocationKeys($input->allocationKeys),
            'prepayments' => array_map(fn (PrepaymentInput $p): array => $this->prepayment($p), $input->prepayments),
        ];
    }

    /**
     * Baut die Eingabe aus dem gespeicherten Snapshot erneut auf.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hydrate(array $payload): StatementCalculationInput
    {
        $period = $this->hydratePeriod($this->arrayValue($payload, 'billingPeriod'));

        $units = [];

        foreach ($this->listValue($payload, 'units') as $row) {
            $units[] = new UnitInput(
                $this->stringValue($row, 'unitKey'),
                $this->stringValue($row, 'label'),
                $this->nullableString($row, 'livingAreaSqm'),
                $this->nullableString($row, 'heatedAreaSqm'),
                $this->nullableString($row, 'coOwnershipShare'),
                $this->individualValues($row),
            );
        }

        $occupancies = [];

        foreach ($this->listValue($payload, 'occupancies') as $row) {
            $occupancyKey = $this->stringValue($row, 'occupancyKey');
            $occupancyPeriod = $this->hydratePeriod($this->arrayValue($row, 'period'));

            $occupancy = new OccupancyInput(
                $occupancyKey,
                $this->stringValue($row, 'unitKey'),
                $occupancyPeriod,
                OccupancyKind::from($this->stringValue($row, 'kind')),
                $this->stringValue($row, 'label'),
                [],
                $this->nullableString($row, 'deliveryAddress'),
            );

            $segments = [];

            foreach ($this->listValue($row, 'personSegments') as $segmentRow) {
                $segments[] = new PersonDaysSegment(
                    $occupancyKey,
                    (int) $this->stringValue($segmentRow, 'persons'),
                    $this->hydratePeriod($this->arrayValue($segmentRow, 'period')),
                );
            }

            $occupancies[] = $segments === [] ? $occupancy : $occupancy->withPersonSegments($segments);
        }

        $costItems = [];

        foreach ($this->listValue($payload, 'costItems') as $row) {
            $laborShare = $row['laborShareCent'] ?? null;

            $costItems[] = new CostItemInput(
                $this->stringValue($row, 'costItemKey'),
                $this->stringValue($row, 'categoryKey'),
                $this->stringValue($row, 'categoryLabel'),
                Money::fromCents((int) $this->stringValue($row, 'totalAmountCent')),
                $this->stringValue($row, 'allocationKeyRef'),
                AllocabilityStatus::from($this->stringValue($row, 'allocabilityStatus')),
                isset($row['servicePeriod']) && is_array($row['servicePeriod'])
                    ? $this->hydratePeriod($row['servicePeriod'])
                    : null,
                $this->nullableString($row, 'inclusionOverrideReason'),
                TaxBenefitCategory::from($this->stringValue($row, 'taxBenefitCategory')),
                is_int($laborShare) ? Money::fromCents($laborShare) : null,
                (bool) ($row['laborShareDisclosed'] ?? true),
                $this->nullableString($row, 'documentReference'),
                (bool) ($row['isCreditNote'] ?? false),
            );
        }

        $keys = $this->hydrateAllocationKeys(
            $this->arrayValue($payload, 'allocationKeys'),
            $occupancies,
            $period
        );

        $prepayments = [];

        foreach ($this->listValue($payload, 'prepayments') as $row) {
            $occupancyKey = $this->stringValue($row, 'occupancyKey');
            $target = Money::fromCents((int) $this->stringValue($row, 'targetCent'));

            if ((bool) ($row['assumedFromTarget'] ?? false)) {
                $prepayments[] = PrepaymentInput::assumedFromTarget(
                    $occupancyKey,
                    $target,
                    $this->nullableString($row, 'source')
                );

                continue;
            }

            $prepayments[] = PrepaymentInput::actual(
                $occupancyKey,
                Money::fromCents((int) $this->stringValue($row, 'actualCent')),
                $target,
                $this->nullableString($row, 'source')
            );
        }

        return new StatementCalculationInput(
            $period,
            $units,
            $occupancies,
            $costItems,
            $keys,
            $prepayments,
            is_string($payload['propertyLabel'] ?? null) ? $payload['propertyLabel'] : '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function result(CalculationRunResult $result): array
    {
        $overview = $result->ownerOverview;

        return [
            'schema' => 1,
            'engineVersion' => $result->engineVersion,
            'statementCount' => $result->statementCount(),
            'statements' => array_map(
                fn (UnitStatementResult $statement): array => $this->statement($statement),
                $result->statements
            ),
            'ownerOverview' => [
                'propertyLabel' => $overview->propertyLabel,
                'includedCostTotalCent' => $overview->includedCostTotal->cents,
                'allocatedToTenantsTotalCent' => $overview->allocatedToTenantsTotal->cents,
                'vacancyTotalCent' => $overview->vacancyTotal->cents,
                'residualTotalCent' => $overview->residualTotal->cents,
                'excludedCostTotalCent' => $overview->excludedCostTotal->cents,
                'balanced' => $overview->isBalanced(),
                'checksumDifferenceCent' => $overview->checksumDifference()->cents,
            ],
            'findings' => array_map(
                fn (CheckFinding $finding): array => $this->finding($finding),
                $result->findings
            ),
        ];
    }

    /**
     * SHA-256 über Eingabe, Ergebnis und Versionen (Abschnitt 6).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     */
    public function hash(array $input, array $result, string $domainVersion, string $rulesetVersion): string
    {
        return hash('sha256', $this->canonical([
            'input' => $input,
            'result' => $result,
            'domainVersion' => $domainVersion,
            'rulesetVersion' => $rulesetVersion,
        ]));
    }

    /**
     * Stabile Zeichenkette einer Nutzlast. Objektschlüssel werden sortiert,
     * damit derselbe Inhalt immer denselben Hash ergibt.
     */
    public function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(fn (mixed $item): string => $this->canonical($item), $value)).']';
            }

            ksort($value);
            $parts = [];

            foreach ($value as $key => $item) {
                $parts[] = json_encode((string) $key, JSON_THROW_ON_ERROR).':'.$this->canonical($item);
            }

            return '{'.implode(',', $parts).'}';
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    // --- Serialisierung einzelner Bausteine ---------------------------------

    /**
     * @return array<string, string>
     */
    private function period(DatePeriodRange $period): array
    {
        return ['start' => $period->startIso(), 'end' => $period->endIso()];
    }

    /**
     * @return array<string, mixed>
     */
    private function unit(UnitInput $unit): array
    {
        $individual = [];

        for ($index = 1; $index <= 5; $index++) {
            $value = $unit->individualValue($index);

            if ($value !== null) {
                $individual[(string) $index] = $value;
            }
        }

        return [
            'unitKey' => $unit->unitKey,
            'label' => $unit->label,
            'livingAreaSqm' => $unit->livingAreaSqm,
            'heatedAreaSqm' => $unit->heatedAreaSqm,
            'coOwnershipShare' => $unit->coOwnershipShare,
            'individualValues' => $individual,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function occupancy(OccupancyInput $occupancy): array
    {
        return [
            'occupancyKey' => $occupancy->occupancyKey,
            'unitKey' => $occupancy->unitKey,
            'period' => $this->period($occupancy->period),
            'kind' => $occupancy->kind->value,
            'label' => $occupancy->label,
            'deliveryAddress' => $occupancy->deliveryAddress,
            'personSegments' => array_map(
                fn (PersonDaysSegment $segment): array => [
                    'persons' => (string) $segment->persons,
                    'period' => $this->period($segment->period),
                ],
                $occupancy->personSegments
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function costItem(CostItemInput $item): array
    {
        return [
            'costItemKey' => $item->costItemKey,
            'categoryKey' => $item->categoryKey,
            'categoryLabel' => $item->categoryLabel,
            'totalAmountCent' => (string) $item->totalAmount->cents,
            'allocationKeyRef' => $item->allocationKeyRef,
            'allocabilityStatus' => $item->allocabilityStatus->value,
            'servicePeriod' => $item->servicePeriod instanceof DatePeriodRange
                ? $this->period($item->servicePeriod)
                : null,
            'inclusionOverrideReason' => $item->inclusionOverrideReason,
            'taxBenefitCategory' => $item->taxBenefitCategory->value,
            'laborShareCent' => $item->laborShare?->cents,
            'laborShareDisclosed' => $item->laborShareDisclosed,
            'documentReference' => $item->documentReference,
            'isCreditNote' => $item->isCreditNote,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prepayment(PrepaymentInput $prepayment): array
    {
        return [
            'occupancyKey' => $prepayment->occupancyKey,
            'targetCent' => (string) $prepayment->targetAmount->cents,
            'actualCent' => (string) $prepayment->deductibleAmount()->cents,
            'assumedFromTarget' => $prepayment->assumedFromTarget,
            'source' => $prepayment->source,
        ];
    }

    /**
     * @param  array<string, DomainAllocationKey>  $keys
     * @return array<string, array<string, mixed>>
     */
    private function allocationKeys(array $keys): array
    {
        $serialized = [];

        foreach ($keys as $ref => $key) {
            $numerators = [];

            foreach ($key->participantKeys() as $participantKey) {
                $numerators[$participantKey] = (string) $key->numeratorFor($participantKey);
            }

            $row = [
                'type' => $key->type()->value,
                'label' => $key->label(),
                'denominator' => (string) $key->denominator(),
                'numerators' => $numerators,
            ];

            if ($key instanceof ConsumptionKey) {
                $row['measurementUnit'] = $key->measurementUnit();
                $row['substituteParticipants'] = $key->substituteParticipants();
            }

            if ($key instanceof PersonDaysKey) {
                $row['fromSegments'] = true;
            }

            $serialized[(string) $ref] = $row;
        }

        ksort($serialized);

        return $serialized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<OccupancyInput>  $occupancies
     * @return array<string, DomainAllocationKey>
     */
    private function hydrateAllocationKeys(array $payload, array $occupancies, DatePeriodRange $period): array
    {
        $keys = [];

        foreach ($payload as $ref => $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = $this->stringValue($row, 'type');
            $label = $this->stringValue($row, 'label');
            $denominator = $this->stringValue($row, 'denominator');
            $numerators = [];

            $raw = $row['numerators'] ?? [];

            if (is_array($raw)) {
                foreach ($raw as $participantKey => $numerator) {
                    $numerators[(string) $participantKey] = is_string($numerator) ? $numerator : (string) $numerator;
                }
            }

            $measurementUnit = is_string($row['measurementUnit'] ?? null) ? $row['measurementUnit'] : '';

            $substitute = [];

            if (isset($row['substituteParticipants']) && is_array($row['substituteParticipants'])) {
                foreach ($row['substituteParticipants'] as $participantKey) {
                    $substitute[] = (string) $participantKey;
                }
            }

            $keys[(string) $ref] = match ($type) {
                'LIVING_AREA' => new LivingAreaKey($numerators, $denominator),
                'HEATED_LIVING_AREA' => new HeatedLivingAreaKey($numerators, $denominator),
                'CO_OWNERSHIP_SHARE' => new CoOwnershipShareKey($numerators, $denominator),
                'PERSONS' => new PersonCountKey($numerators, $denominator),
                'UNITS' => new UnitCountKey($numerators, $denominator),
                'PERSON_DAYS' => PersonDaysKey::fromSegments($this->segmentsOf($occupancies), $period),
                'CONSUMPTION' => ConsumptionKey::create($numerators, $measurementUnit, $substitute, $denominator),
                'DIRECT_ASSIGNMENT' => DirectAssignmentKey::fromCentValues($numerators),
                default => new IndividualKey(
                    (int) substr($type, -1),
                    $numerators,
                    $denominator,
                    $label,
                    $measurementUnit
                ),
            };
        }

        return $keys;
    }

    /**
     * @param  list<OccupancyInput>  $occupancies
     * @return list<PersonDaysSegment>
     */
    private function segmentsOf(array $occupancies): array
    {
        $segments = [];

        foreach ($occupancies as $occupancy) {
            foreach ($occupancy->personSegments as $segment) {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /**
     * @return array<string, mixed>
     */
    private function statement(UnitStatementResult $statement): array
    {
        return [
            'occupancyKey' => $statement->occupancyKey,
            'unitKey' => $statement->unitKey,
            'unitLabel' => $statement->unitLabel,
            'tenantLabel' => $statement->tenantLabel,
            'usagePeriod' => $this->period($statement->usagePeriod),
            'usageDays' => $statement->usageDays(),
            'periodDays' => $statement->billingPeriod->days(),
            'allocableTotalCent' => $statement->allocableTotal->cents,
            'prepaymentTargetCent' => $statement->prepaymentTarget->cents,
            'prepaymentActualCent' => $statement->prepaymentActual->cents,
            'prepaymentAssumedFromTarget' => $statement->prepaymentAssumedFromTarget,
            'balanceCent' => $statement->balance->cents,
            'roundingAdjustmentTotalCent' => $statement->totalRoundingAdjustmentCent(),
            'taxBenefitHouseholdCent' => $statement->taxBenefitHouseholdServices->cents,
            'taxBenefitCraftsmanCent' => $statement->taxBenefitCraftsmanServices->cents,
            'assumptions' => $statement->assumptions,
            'lines' => array_map(fn (StatementLine $line): array => $this->line($line), $statement->lines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(StatementLine $line): array
    {
        return [
            'costItemKey' => $line->costItemKey,
            'categoryKey' => $line->categoryKey,
            'categoryLabel' => $line->categoryLabel,
            'totalCostCent' => $line->totalCost->cents,
            'allocationKeyLabel' => $line->allocationKeyLabel,
            'allocationExplanation' => $line->allocationExplanation,
            'numerator' => $line->numerator,
            'denominator' => $line->denominator,
            'timeFactorDaysUsed' => $line->timeFactor->daysUsed,
            'timeFactorDaysInPeriod' => $line->timeFactor->daysInPeriod,
            'timeFactorIncludedInKey' => $line->timeFactor->includedInAllocationKey,
            'shareCent' => $line->share->cents,
            'roundingAdjustmentCent' => $line->roundingAdjustmentCent,
            'allocabilityStatus' => $line->allocabilityStatus->value,
            'includedByOverride' => $line->includedByOverride,
            'taxBenefitCategory' => $line->taxBenefitCategory->value,
            'taxBenefitLaborShareCent' => $line->taxBenefitLaborShare?->cents,
            'laborShareDisclosed' => $line->laborShareDisclosed,
            'substituteDistributionConfirmed' => $line->substituteDistributionConfirmed,
            'calculationExplanation' => $line->calculationExplanation(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(CheckFinding $finding): array
    {
        return [
            'code' => $finding->code->value,
            'severity' => $finding->severity->value,
            'message' => $finding->message,
        ];
    }

    // --- Lesehilfen ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function arrayValue(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function listValue(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];
        $rows = [];

        if (is_array($value)) {
            foreach ($value as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nullableString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function individualValues(array $row): array
    {
        $values = [];
        $raw = $row['individualValues'] ?? [];

        if (is_array($raw)) {
            foreach ($raw as $index => $value) {
                if (is_string($value) && $value !== '') {
                    $values[(int) $index] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hydratePeriod(array $payload): DatePeriodRange
    {
        return DatePeriodRange::fromIso(
            $this->stringValue($payload, 'start'),
            $this->stringValue($payload, 'end')
        );
    }
}
