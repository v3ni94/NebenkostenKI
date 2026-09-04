<?php

declare(strict_types=1);

namespace App\Application\Calculation;

use App\Application\Calculation\Dto\AssembledCalculationInput;
use App\Domain\Allocation\AllocationKey as DomainAllocationKey;
use App\Domain\Allocation\AllocationKeyScope;
use App\Domain\Allocation\ConsumptionKeyBuilder;
use App\Domain\Allocation\ConsumptionRecord;
use App\Domain\Allocation\CoOwnershipShareKey;
use App\Domain\Allocation\DirectAssignmentKey;
use App\Domain\Allocation\HeatedLivingAreaKey;
use App\Domain\Allocation\IndividualKey;
use App\Domain\Allocation\LivingAreaKey;
use App\Domain\Allocation\MissingInterimReadingException;
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
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Domain\Period\PeriodCoverage;
use App\Domain\Support\GermanNumberFormatter;
use App\Enums\AllocationKeyType;
use App\Enums\ApportionmentStatus;
use App\Enums\CostItemStatus;
use App\Enums\HeatingSupplyCase;
use App\Enums\Paragraph35aType;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\ManualOverride;
use App\Models\Prepayment;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\VacancyPeriod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * Baut aus den Modellen eines Abrechnungslaufs die validierte Eingabe der
 * deterministischen Berechnungsengine.
 *
 * VERBINDLICHE REGELN
 *
 *  1. Dezimalwerte werden NIEMALS nach float gecastet. Flächen, Anteile,
 *     Zähler und Verbrauchswerte werden als Zeichenketten an brick/math
 *     übergeben (ARCHITECTURE.md, ADR-004).
 *  2. Geldbeträge sind ausschließlich Integer in Cent und werden über
 *     App\Domain\Money\Money geführt.
 *  3. Fehlt ein Pflichtwert, wird nicht geschätzt. Es wird eine
 *     CalculationInputException mit verständlichem Text erzeugt.
 *  4. Heizkostenfall C (dezentrale Versorgung): Heizkostenpositionen werden
 *     nicht als Vermieterkosten angesetzt und deshalb nicht übergeben.
 *  5. WEG-Schlüssel und mietvertraglicher Umlageschlüssel werden nicht
 *     gleichgesetzt. Es wird ausschließlich der in Schritt 8 festgelegte
 *     Schlüssel verwendet.
 *
 * Schlüsselkonvention:
 *   unitKey       Kennung der Einheit
 *   occupancyKey  Kennung des Mietverhältnisses beziehungsweise
 *                 self::VACANCY_PREFIX plus Kennung des Leerstands
 *   costItemKey   Kennung der Kostenposition
 */
final class BillingRunInputAssembler
{
    public const string VACANCY_PREFIX = 'LEERSTAND-';

    public const string ALLOCATION_KEY_PREFIX = 'SCHLUESSEL-';

    /**
     * Feldname der protokollierten Bestätigung einer Ersatzverteilung.
     */
    public const string SUBSTITUTE_FIELD = 'ersatzverteilung_ohne_zwischenablesung';

    public function __construct(private readonly ConsumptionKeyBuilder $consumption = new ConsumptionKeyBuilder) {}

    public function assemble(BillingRun $billingRun): AssembledCalculationInput
    {
        $billingRun->loadMissing([
            'property.units.tenancies.persons',
            'property.units.tenancies.occupancyPeriods',
            'property.units.vacancyPeriods',
            'costItems.costCategory',
            'allocationKeys.values',
            'prepayments',
        ]);

        $period = new DatePeriodRange($billingRun->period_start, $billingRun->period_end);

        $units = $this->units($billingRun);

        if ($units === []) {
            throw CalculationInputException::noUnits($billingRun->property->label);
        }

        $unitIdByUnitKey = [];

        foreach ($units as $unit) {
            $unitIdByUnitKey[$unit->unitKey] = $unit->unitKey;
        }

        [$occupancies, $tenancyIdByOccupancyKey, $labelByOccupancyKey] = $this->occupancies($billingRun, $period);

        $relevantItems = $this->relevantCostItems($billingRun);

        $keys = $this->allocationKeys($billingRun, $period, $units, $occupancies, $relevantItems);

        [$costItems, $categoryIdByCostItemKey, $heatingCategoryKeys, $directKeys] = $this->costItems(
            $relevantItems,
            $units,
            $keys['refByCategory'],
            $keys['refByCostItem']
        );

        $prepayments = $this->prepayments($billingRun, $tenancyIdByOccupancyKey, $labelByOccupancyKey);

        $input = new StatementCalculationInput(
            $period,
            $units,
            $occupancies,
            $costItems,
            $keys['domain'] + $directKeys['domain'],
            $prepayments,
            $billingRun->property->label,
        );

        return new AssembledCalculationInput(
            $input,
            $tenancyIdByOccupancyKey,
            $unitIdByUnitKey,
            $categoryIdByCostItemKey,
            $heatingCategoryKeys,
            $keys['typeByRef'] + $directKeys['typeByRef'],
        );
    }

    /**
     * @return list<UnitInput>
     */
    private function units(BillingRun $billingRun): array
    {
        $units = [];

        foreach ($this->sortedUnits($billingRun) as $unit) {
            $individual = [];

            for ($index = 1; $index <= 5; $index++) {
                $value = $this->decimalString($unit->getAttribute('individual_key_'.$index.'_value'));

                if ($value !== null) {
                    $individual[$index] = $value;
                }
            }

            $units[] = new UnitInput(
                (string) $unit->getKey(),
                $unit->label,
                $this->decimalString($unit->living_area_sqm),
                $this->decimalString($unit->heated_area_sqm),
                $this->decimalString($unit->mea),
                $individual,
            );
        }

        return $units;
    }

    /**
     * @return list<Unit>
     */
    private function sortedUnits(BillingRun $billingRun): array
    {
        $units = $billingRun->property->units->sortBy(
            static fn (Unit $unit): string => (string) $unit->getKey()
        )->values()->all();

        /** @var list<Unit> $units */
        return $units;
    }

    /**
     * Mietverhältnisse und erfasste Leerstände, begrenzt auf den
     * Abrechnungszeitraum.
     *
     * @return array{0: list<OccupancyInput>, 1: array<string, string>, 2: array<string, string>}
     */
    private function occupancies(BillingRun $billingRun, DatePeriodRange $period): array
    {
        $occupancies = [];
        $tenancyIds = [];
        $labels = [];

        foreach ($this->sortedUnits($billingRun) as $unit) {
            $unitKey = (string) $unit->getKey();

            $tenancies = $unit->tenancies->sortBy(
                static fn (Tenancy $tenancy): string => (string) $tenancy->getKey()
            );

            foreach ($tenancies as $tenancy) {
                $range = $this->tenancyRange($tenancy, $period);

                if (! $range instanceof DatePeriodRange) {
                    continue;
                }

                $occupancyKey = (string) $tenancy->getKey();
                $label = $tenancy->tenant_display_name;

                $occupancy = OccupancyInput::tenancy(
                    $occupancyKey,
                    $unitKey,
                    $range,
                    $label,
                    $this->deliveryAddress($tenancy),
                );

                $segments = $this->personSegments($tenancy, $range);

                if ($segments !== []) {
                    $occupancy = $occupancy->withPersonSegments($segments);
                }

                $occupancies[] = $occupancy;
                $tenancyIds[$occupancyKey] = $occupancyKey;
                $labels[$occupancyKey] = $label;
            }

            $vacancies = $unit->vacancyPeriods->sortBy(
                static fn (VacancyPeriod $vacancy): string => (string) $vacancy->getKey()
            );

            foreach ($vacancies as $vacancy) {
                $range = $period->intersect(new DatePeriodRange($vacancy->starts_on, $vacancy->ends_on));

                if (! $range instanceof DatePeriodRange) {
                    continue;
                }

                $key = self::VACANCY_PREFIX.$vacancy->getKey();
                $occupancies[] = OccupancyInput::vacancy($key, $unitKey, $range);
                $labels[$key] = 'Leerstand';
            }
        }

        return [$occupancies, $tenancyIds, $labels];
    }

    private function tenancyRange(Tenancy $tenancy, DatePeriodRange $period): ?DatePeriodRange
    {
        $end = $tenancy->ends_on instanceof Carbon ? $tenancy->ends_on : $period->end;

        if ($end < $tenancy->starts_on) {
            return null;
        }

        return $period->intersect(new DatePeriodRange($tenancy->starts_on, $end));
    }

    private function deliveryAddress(Tenancy $tenancy): ?string
    {
        $parts = array_filter([
            $tenancy->delivery_address_line,
            trim(($tenancy->delivery_postal_code ?? '').' '.($tenancy->delivery_city ?? '')),
        ], static fn (?string $part): bool => is_string($part) && trim($part) !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @return list<PersonDaysSegment>
     */
    private function personSegments(Tenancy $tenancy, DatePeriodRange $range): array
    {
        $segments = [];

        foreach ($tenancy->occupancyPeriods as $occupancyPeriod) {
            $segmentRange = $range->intersect(
                new DatePeriodRange($occupancyPeriod->starts_on, $occupancyPeriod->ends_on)
            );

            if (! $segmentRange instanceof DatePeriodRange) {
                continue;
            }

            $segments[] = new PersonDaysSegment(
                (string) $tenancy->getKey(),
                $occupancyPeriod->person_count,
                $segmentRange,
            );
        }

        return $segments;
    }

    /**
     * Baut alle Verteilerschlüssel des Laufs und die Zuordnung Kostenart bzw.
     * Kostenposition auf die Schlüsselreferenz.
     *
     * @param  list<UnitInput>  $units
     * @param  list<OccupancyInput>  $occupancies
     * @param  list<CostItem>  $relevantItems
     * @return array{
     *     domain: array<string, DomainAllocationKey>,
     *     refByCategory: array<string, string>,
     *     refByCostItem: array<string, string>,
     *     typeByRef: array<string, string>
     * }
     */
    private function allocationKeys(
        BillingRun $billingRun,
        DatePeriodRange $period,
        array $units,
        array $occupancies,
        array $relevantItems,
    ): array {
        $domain = [];
        $refByCategory = [];
        $refByCostItem = [];
        $typeByRef = [];

        $substituteUnits = $this->confirmedSubstituteUnits($billingRun);
        $participantDays = $this->participantDaysByUnit($units, $occupancies, $period);
        $directTargets = $this->directAssignmentTargets($relevantItems);

        $records = $billingRun->allocationKeys->sortBy(
            static fn (AllocationKey $key): string => (string) $key->getKey()
        );

        foreach ($records as $record) {
            $ref = self::ALLOCATION_KEY_PREFIX.$record->getKey();

            $domain[$ref] = $this->domainKey(
                $record,
                $billingRun,
                $period,
                $units,
                $occupancies,
                $participantDays,
                $substituteUnits,
                $directTargets,
            );
            $typeByRef[$ref] = $record->key_type->value;

            if ($record->cost_item_id !== null) {
                $refByCostItem[$record->cost_item_id] = $ref;

                continue;
            }

            if ($record->cost_category_id !== null) {
                $refByCategory[$record->cost_category_id] = $ref;
            }
        }

        return [
            'domain' => $domain,
            'refByCategory' => $refByCategory,
            'refByCostItem' => $refByCostItem,
            'typeByRef' => $typeByRef,
        ];
    }

    /**
     * Einheiten mit ausdrücklich bestätigter Ersatzverteilung.
     *
     * @return list<string>
     */
    private function confirmedSubstituteUnits(BillingRun $billingRun): array
    {
        /** @var list<string> $ids */
        $ids = ManualOverride::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('subject_type', Unit::class)
            ->where('field', self::SUBSTITUTE_FIELD)
            ->pluck('subject_id')
            ->map(static fn (mixed $id): string => is_string($id) ? $id : '')
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * @param  list<UnitInput>  $units
     * @param  list<OccupancyInput>  $occupancies
     * @return array<string, array<string, int>>
     */
    private function participantDaysByUnit(array $units, array $occupancies, DatePeriodRange $period): array
    {
        $days = [];

        foreach ($units as $unit) {
            $days[$unit->unitKey] = [];
        }

        foreach ($occupancies as $occupancy) {
            $clipped = $period->intersect($occupancy->period);

            if (! $clipped instanceof DatePeriodRange) {
                continue;
            }

            $days[$occupancy->unitKey][$occupancy->occupancyKey] = $clipped->days();
        }

        return $days;
    }

    /**
     * Zu verteilende Beträge in Cent je Kostenposition und je Kostenart. Sie
     * dienen der Direktzuordnung als Nenner, damit die zugeordneten Beträge
     * Festbeträge bleiben und ein nicht zugeordneter Rest beim Eigentümer
     * verbleibt. Gezählt werden nur Positionen, die auf Mieter umgelegt werden.
     *
     * @param  list<CostItem>  $items
     * @return array{item: array<string, int>, category: array<string, int>}
     */
    private function directAssignmentTargets(array $items): array
    {
        $byItem = [];
        $byCategory = [];

        foreach ($items as $item) {
            if (! $this->isIncludedInTenantAllocation($item)) {
                continue;
            }

            $byItem[(string) $item->getKey()] = $item->amount_cent;

            if ($item->cost_category_id !== null) {
                $byCategory[$item->cost_category_id] = ($byCategory[$item->cost_category_id] ?? 0) + $item->amount_cent;
            }
        }

        return ['item' => $byItem, 'category' => $byCategory];
    }

    /**
     * Spiegelt CostItemInput::isIncludedInTenantAllocation() auf dem Modell.
     */
    private function isIncludedInTenantAllocation(CostItem $item): bool
    {
        if ($this->allocability($item)->isAllocatedByDefault()) {
            return true;
        }

        $reason = $item->apportionment_override_reason;

        return is_string($reason) && trim($reason) !== '';
    }

    /**
     * @param  list<UnitInput>  $units
     * @param  list<OccupancyInput>  $occupancies
     * @param  array<string, array<string, int>>  $participantDays
     * @param  list<string>  $substituteUnits
     * @param  array{item: array<string, int>, category: array<string, int>}  $directTargets
     */
    private function domainKey(
        AllocationKey $record,
        BillingRun $billingRun,
        DatePeriodRange $period,
        array $units,
        array $occupancies,
        array $participantDays,
        array $substituteUnits,
        array $directTargets,
    ): DomainAllocationKey {
        $label = $record->label ?? $record->key_type->label();
        $denominator = $this->decimalString($record->denominator);

        return match ($record->key_type) {
            AllocationKeyType::WOHNFLAECHE => new LivingAreaKey(
                $this->unitValues($record, $units, $label, static fn (UnitInput $unit): ?string => $unit->livingAreaSqm),
                $denominator
            ),
            AllocationKeyType::BEHEIZTE_WOHNFLAECHE => new HeatedLivingAreaKey(
                $this->unitValues($record, $units, $label, static fn (UnitInput $unit): ?string => $unit->heatedAreaSqm),
                $denominator
            ),
            AllocationKeyType::MEA => new CoOwnershipShareKey(
                $this->unitValues($record, $units, $label, static fn (UnitInput $unit): ?string => $unit->coOwnershipShare),
                $denominator ?? $this->decimalString($billingRun->property->mea_denominator)
            ),
            AllocationKeyType::PERSONEN => new PersonCountKey(
                $this->unitValues($record, $units, $label, static fn (UnitInput $unit): ?string => null),
                $denominator
            ),
            AllocationKeyType::EINHEITEN => $this->unitCountKey($record, $units, $label),
            AllocationKeyType::PERSONENTAGE => PersonDaysKey::fromSegments(
                $this->allPersonSegments($occupancies, $label),
                $period
            ),
            AllocationKeyType::DIREKT => $this->directAssignmentKey($record, $label, $directTargets),
            AllocationKeyType::VERBRAUCH => $this->consumptionKey(
                $record,
                $label,
                $participantDays,
                $substituteUnits,
                $units,
                $occupancies
            ),
            AllocationKeyType::INDIVIDUELL_1,
            AllocationKeyType::INDIVIDUELL_2,
            AllocationKeyType::INDIVIDUELL_3,
            AllocationKeyType::INDIVIDUELL_4,
            AllocationKeyType::INDIVIDUELL_5 => $this->individualKey($record, $units, $label, $denominator),
        };
    }

    /**
     * Zähler je Einheit. Zuerst die in Schritt 8 erfassten Werte, sonst der
     * Stammwert der Einheit. Fehlt beides, wird nicht geschätzt.
     *
     * @param  list<UnitInput>  $units
     * @param  callable(UnitInput): ?string  $fallback
     * @return array<string, string>
     */
    private function unitValues(AllocationKey $record, array $units, string $label, callable $fallback): array
    {
        $stored = [];

        foreach ($record->values as $value) {
            if ($value->unit_id === null) {
                continue;
            }

            $numerator = $this->decimalString($value->numerator);

            if ($numerator !== null) {
                $stored[$value->unit_id] = $numerator;
            }
        }

        $values = [];

        foreach ($units as $unit) {
            $numerator = $stored[$unit->unitKey] ?? $fallback($unit);

            if ($numerator === null) {
                throw CalculationInputException::missingAllocationValue($label, $unit->label);
            }

            $values[$unit->unitKey] = $numerator;
        }

        if ($values === []) {
            throw CalculationInputException::emptyAllocationKey($label);
        }

        return $values;
    }

    /**
     * @param  list<UnitInput>  $units
     */
    private function unitCountKey(AllocationKey $record, array $units, string $label): UnitCountKey
    {
        $explicit = [];

        foreach ($record->values as $value) {
            if ($value->unit_id === null) {
                continue;
            }

            $numerator = $this->decimalString($value->numerator);

            if ($numerator !== null) {
                $explicit[$value->unit_id] = $numerator;
            }
        }

        if ($explicit !== []) {
            $values = [];

            foreach ($units as $unit) {
                if (! array_key_exists($unit->unitKey, $explicit)) {
                    throw CalculationInputException::missingAllocationValue($label, $unit->label);
                }

                $values[$unit->unitKey] = $explicit[$unit->unitKey];
            }

            return new UnitCountKey($values);
        }

        return UnitCountKey::forUnits(array_map(
            static fn (UnitInput $unit): string => $unit->unitKey,
            $units
        ));
    }

    /**
     * @param  list<UnitInput>  $units
     */
    private function individualKey(AllocationKey $record, array $units, string $label, ?string $denominator): IndividualKey
    {
        $index = (int) substr($record->key_type->value, -1);

        $values = $this->unitValues(
            $record,
            $units,
            $label,
            static fn (UnitInput $unit): ?string => $unit->individualValue($index)
        );

        return new IndividualKey(
            $index,
            $values,
            $denominator,
            $record->label,
            $record->measurement_unit ?? '',
        );
    }

    /**
     * Personenabschnitte aller Nutzungszeiträume. Jedes Mietverhältnis muss
     * für seinen gesamten Nutzungszeitraum Personenangaben haben; sonst
     * erhielte es stillschweigend das Gewicht null und die übrigen Mieter
     * trügen seinen Anteil. Fehlende Angaben werden nicht geschätzt.
     *
     * @param  list<OccupancyInput>  $occupancies
     * @return list<PersonDaysSegment>
     */
    private function allPersonSegments(array $occupancies, string $label): array
    {
        $segments = [];

        foreach ($occupancies as $occupancy) {
            if ($occupancy->kind === OccupancyKind::TENANCY) {
                $ranges = array_map(
                    static fn (PersonDaysSegment $segment): DatePeriodRange => $segment->period,
                    $occupancy->personSegments
                );

                if ($ranges === [] || ! PeriodCoverage::isFullyCovered($occupancy->period, $ranges)) {
                    throw CalculationInputException::missingPersonSegments($label, $occupancy->label);
                }
            }

            foreach ($occupancy->personSegments as $segment) {
                $segments[] = $segment;
            }
        }

        if ($segments === []) {
            throw CalculationInputException::emptyAllocationKey($label);
        }

        return $segments;
    }

    /**
     * Direktzuordnung: Zähler sind Festbeträge in Cent je Nutzungszeitraum.
     * Nenner ist der zu verteilende Positionsbetrag, damit ein nicht
     * zugeordneter Rest als Restanteil beim Eigentümer ausgewiesen wird.
     * Übersteigt die Summe der Zähler den Positionsbetrag, bricht der Aufbau
     * ab. Ohne positiven Positionsbetrag (Gutschrift) bleiben die Zähler
     * reine Gewichte.
     *
     * @param  array{item: array<string, int>, category: array<string, int>}  $directTargets
     */
    private function directAssignmentKey(AllocationKey $record, string $label, array $directTargets): DirectAssignmentKey
    {
        $values = $this->occupancyValues($record, $label);

        $target = null;

        if ($record->cost_item_id !== null) {
            $target = $directTargets['item'][$record->cost_item_id] ?? null;
        } elseif ($record->cost_category_id !== null) {
            $target = $directTargets['category'][$record->cost_category_id] ?? null;
        }

        if ($target === null || $target <= 0) {
            return DirectAssignmentKey::fromCentValues($values);
        }

        $sum = BigDecimal::zero();

        foreach ($values as $value) {
            $sum = $sum->plus(BigDecimal::of(str_replace(',', '.', $value)));
        }

        if ($sum->isGreaterThan(BigDecimal::of($target))) {
            throw CalculationInputException::directAssignmentExceedsAmount(
                $label,
                GermanNumberFormatter::decimal($sum->dividedBy(100, 2, RoundingMode::HALF_UP), 2),
                Money::fromCents($target)->formatAmount()
            );
        }

        return DirectAssignmentKey::fromCentValues($values, (string) $target);
    }

    /**
     * Zähler je Nutzungszeitraum, z. B. für die direkte Zuordnung.
     *
     * @return array<string, string>
     */
    private function occupancyValues(AllocationKey $record, string $label): array
    {
        $values = [];

        foreach ($record->values as $value) {
            if ($value->tenancy_id === null) {
                continue;
            }

            $numerator = $this->decimalString($value->numerator);

            if ($numerator === null) {
                continue;
            }

            $values[$value->tenancy_id] = $numerator;
        }

        if ($values === []) {
            throw CalculationInputException::emptyAllocationKey($label);
        }

        return $values;
    }

    /**
     * @param  array<string, array<string, int>>  $participantDays
     * @param  list<string>  $substituteUnits
     * @param  list<UnitInput>  $units
     * @param  list<OccupancyInput>  $occupancies
     */
    private function consumptionKey(
        AllocationKey $record,
        string $label,
        array $participantDays,
        array $substituteUnits,
        array $units,
        array $occupancies,
    ): DomainAllocationKey {
        $records = [];

        // Erfasste Leerstände gehören dem Eigentümer und verlangen keine
        // Zwischenablesung.
        $ownerParticipants = [];

        foreach ($occupancies as $occupancy) {
            if ($occupancy->isChargedToOwner()) {
                $ownerParticipants[] = $occupancy->occupancyKey;
            }
        }

        foreach ($record->values as $value) {
            $numerator = $this->decimalString($value->numerator);

            if ($numerator === null) {
                continue;
            }

            if ($value->tenancy_id !== null) {
                $records[] = ConsumptionRecord::forOccupancy(
                    $this->unitKeyForConsumption($value, $participantDays),
                    $value->tenancy_id,
                    $numerator
                );

                continue;
            }

            if ($value->unit_id !== null) {
                $records[] = ConsumptionRecord::forUnit($value->unit_id, $numerator);
            }
        }

        if ($records === []) {
            throw CalculationInputException::emptyAllocationKey($label);
        }

        try {
            return $this->consumption->build(
                $records,
                $participantDays,
                $record->measurement_unit ?? '',
                $substituteUnits,
                $ownerParticipants,
            );
        } catch (MissingInterimReadingException $exception) {
            throw CalculationInputException::unconfirmedSubstituteDistribution(
                $this->unitLabel($units, $exception->getMessage())
            );
        }
    }

    /**
     * @param  array<string, array<string, int>>  $participantDays
     */
    private function unitKeyForConsumption(AllocationKeyValue $value, array $participantDays): string
    {
        if ($value->unit_id !== null) {
            return $value->unit_id;
        }

        foreach ($participantDays as $unitKey => $participants) {
            if (array_key_exists((string) $value->tenancy_id, $participants)) {
                return (string) $unitKey;
            }
        }

        return '';
    }

    /**
     * @param  list<UnitInput>  $units
     */
    private function unitLabel(array $units, string $haystack): string
    {
        foreach ($units as $unit) {
            if (str_contains($haystack, $unit->unitKey)) {
                return $unit->label;
            }
        }

        return 'ohne Bezeichnung';
    }

    /**
     * Kostenpositionen, die in die Berechnung eingehen: nicht verworfen und
     * im Heizkostenfall C keine Heizkosten.
     *
     * @return list<CostItem>
     */
    private function relevantCostItems(BillingRun $billingRun): array
    {
        $decentralized = $billingRun->heating_supply_case === HeatingSupplyCase::DEZENTRAL;

        $sorted = $billingRun->costItems->sortBy(
            static fn (CostItem $item): string => (string) $item->getKey()
        );

        $items = [];

        foreach ($sorted as $item) {
            if ($item->status === CostItemStatus::VERWORFEN) {
                continue;
            }

            // Heizkostenfall C: der Mieter bezieht die Energie direkt. Es
            // werden keine Heizkosten als Vermieterkosten angesetzt.
            if ($decentralized && ($item->is_heating_cost || $item->is_warm_water_cost)) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Schlüsselreferenz einer Position: zuerst ein positionsbezogener
     * Schlüssel, dann die Direktzuordnung zu einer Einheit aus der
     * Kostenprüfung, zuletzt der Schlüssel der Kostenart aus Schritt 8.
     *
     * @param  list<CostItem>  $relevantItems
     * @param  list<UnitInput>  $units
     * @param  array<string, string>  $refByCategory
     * @param  array<string, string>  $refByCostItem
     * @return array{
     *     0: list<CostItemInput>,
     *     1: array<string, string|null>,
     *     2: list<string>,
     *     3: array{domain: array<string, DomainAllocationKey>, typeByRef: array<string, string>}
     * }
     */
    private function costItems(array $relevantItems, array $units, array $refByCategory, array $refByCostItem): array
    {
        $items = [];
        $categoryIds = [];
        $heatingCategories = [];
        $directKeys = ['domain' => [], 'typeByRef' => []];

        foreach ($relevantItems as $item) {
            $category = $item->costCategory;
            $categoryKey = $category !== null ? (string) $category->getKey() : 'OHNE_KATEGORIE';
            $categoryLabel = $category !== null ? $category->name : 'Ohne Kostenart';

            $key = (string) $item->getKey();
            $ref = $refByCostItem[$key] ?? null;

            if ($ref === null && $item->direct_unit_id !== null) {
                $ref = self::ALLOCATION_KEY_PREFIX.'POS-'.$key;
                $directKeys['domain'][$ref] = $this->directUnitKey($item, $units);
                $directKeys['typeByRef'][$ref] = AllocationKeyType::DIREKT->value;
            }

            $ref ??= $category !== null ? ($refByCategory[(string) $category->getKey()] ?? null) : null;

            if ($ref === null) {
                throw CalculationInputException::missingAllocationKey($categoryLabel);
            }

            if ($category !== null && ($category->is_heating_related || $category->is_warm_water_related)
                && ! in_array($categoryKey, $heatingCategories, true)) {
                $heatingCategories[] = $categoryKey;
            }

            $items[] = new CostItemInput(
                $key,
                $categoryKey,
                $categoryLabel,
                Money::fromCents($item->amount_cent),
                $ref,
                $this->allocability($item),
                $this->servicePeriod($item),
                $item->apportionment_override_reason,
                $this->taxBenefit($item->paragraph_35a_type),
                $item->labor_share_cent === null ? null : Money::fromCents($item->labor_share_cent),
                $item->paragraph_35a_type === Paragraph35aType::NONE || $item->labor_share_cent !== null,
                $item->invoice_number,
                $item->amount_cent < 0,
            );

            $categoryIds[$key] = $category !== null ? (string) $category->getKey() : null;
        }

        if ($items === []) {
            throw CalculationInputException::noCostItems();
        }

        return [$items, $categoryIds, $heatingCategories, $directKeys];
    }

    /**
     * Positionsbezogener Schlüssel für eine in der Kostenprüfung direkt einer
     * Einheit zugeordnete Position: Bezugsebene UNIT, die zugeordnete Einheit
     * trägt den Betrag vollständig, alle übrigen Einheiten den Anteil null.
     * Ein Mieterwechsel oder Leerstand innerhalb der Einheit wird damit
     * taggenau über den Zeitfaktor berücksichtigt.
     *
     * @param  list<UnitInput>  $units
     */
    private function directUnitKey(CostItem $item, array $units): UnitCountKey
    {
        $directUnitId = (string) $item->direct_unit_id;
        $values = [];
        $found = false;

        foreach ($units as $unit) {
            $isTarget = $unit->unitKey === $directUnitId;
            $found = $found || $isTarget;
            $values[$unit->unitKey] = $isTarget ? '1' : '0';
        }

        if (! $found) {
            throw CalculationInputException::unknownDirectUnit($item->description);
        }

        return new UnitCountKey($values);
    }

    private function allocability(CostItem $item): AllocabilityStatus
    {
        if ($item->excluded_from_apportionment) {
            return AllocabilityStatus::NOT_ALLOCABLE;
        }

        return match ($item->apportionment_status) {
            ApportionmentStatus::UMLAGEFAEHIG => AllocabilityStatus::ALLOCABLE,
            ApportionmentStatus::NICHT_UMLAGEFAEHIG => AllocabilityStatus::NOT_ALLOCABLE,
            ApportionmentStatus::PRUEFPFLICHTIG => AllocabilityStatus::REVIEW_REQUIRED,
        };
    }

    private function taxBenefit(Paragraph35aType $type): TaxBenefitCategory
    {
        return match ($type) {
            Paragraph35aType::NONE => TaxBenefitCategory::NONE,
            Paragraph35aType::HAUSHALTSNAHE_DIENSTLEISTUNG => TaxBenefitCategory::HOUSEHOLD_SERVICE,
            Paragraph35aType::HANDWERKERLEISTUNG => TaxBenefitCategory::CRAFTSMAN_SERVICE,
        };
    }

    private function servicePeriod(CostItem $item): ?DatePeriodRange
    {
        if (! $item->service_period_start instanceof Carbon || ! $item->service_period_end instanceof Carbon) {
            return null;
        }

        return new DatePeriodRange($item->service_period_start, $item->service_period_end);
    }

    /**
     * Vorauszahlungen je Mietverhältnis. Betriebskosten- und
     * Heizkostenvorauszahlung werden zur abzuziehenden Summe zusammengefasst.
     *
     * @param  array<string, string>  $tenancyIdByOccupancyKey
     * @param  array<string, string>  $labels
     * @return list<PrepaymentInput>
     */
    private function prepayments(BillingRun $billingRun, array $tenancyIdByOccupancyKey, array $labels): array
    {
        /** @var array<string, list<Prepayment>> $byTenancy */
        $byTenancy = [];

        foreach ($billingRun->prepayments as $prepayment) {
            $byTenancy[$prepayment->tenancy_id][] = $prepayment;
        }

        $inputs = [];

        foreach (array_keys($tenancyIdByOccupancyKey) as $occupancyKey) {
            $label = $labels[$occupancyKey] ?? $occupancyKey;
            $rows = $byTenancy[$occupancyKey] ?? [];

            if ($rows === []) {
                throw CalculationInputException::missingPrepayment($label);
            }

            $target = 0;
            $actual = 0;
            $assumed = false;
            $missingActual = false;
            $source = null;

            foreach ($rows as $row) {
                $target += $row->target_cent;

                if ($row->assumed_equal_to_target) {
                    $assumed = true;
                    $actual += $row->actual_cent ?? $row->target_cent;
                } elseif ($row->actual_cent === null) {
                    $missingActual = true;
                } else {
                    $actual += $row->actual_cent;
                }

                $source ??= $row->source->label();
            }

            if ($missingActual) {
                throw CalculationInputException::missingActualPrepayment($label);
            }

            $inputs[] = $assumed
                ? PrepaymentInput::assumedFromTarget($occupancyKey, Money::fromCents($target), $source)
                : PrepaymentInput::actual(
                    $occupancyKey,
                    Money::fromCents($actual),
                    Money::fromCents($target),
                    $source
                );
        }

        return $inputs;
    }

    /**
     * Dezimalwert als Zeichenkette. Es wird ausdrücklich NICHT nach float
     * gecastet (ADR-004).
     */
    private function decimalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Vollständige Liste der unterstützten Schlüsselbezugsebenen. Wird von der
     * Oberfläche genutzt, um Werte je Einheit oder je Mietverhältnis
     * abzufragen.
     */
    public static function scopeOf(AllocationKeyType $type): AllocationKeyScope
    {
        return match ($type) {
            AllocationKeyType::PERSONENTAGE, AllocationKeyType::VERBRAUCH, AllocationKeyType::DIREKT => AllocationKeyScope::OCCUPANCY,
            default => AllocationKeyScope::UNIT,
        };
    }
}
