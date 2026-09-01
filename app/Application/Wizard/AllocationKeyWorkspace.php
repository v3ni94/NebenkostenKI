<?php

declare(strict_types=1);

namespace App\Application\Wizard;

use App\Application\Account\AuditRecorder;
use App\Application\Calculation\BillingRunInputAssembler;
use App\Application\Wizard\Dto\AllocationKeyRow;
use App\Application\Wizard\Dto\AllocationValueRow;
use App\Domain\Allocation\AllocationKeyScope;
use App\Domain\Period\DatePeriodRange;
use App\Domain\Support\GermanNumberFormatter;
use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Enums\CostItemStatus;
use App\Enums\HeatingSupplyCase;
use App\Enums\ValueSource;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use App\Models\BillingRun;
use App\Models\CostCategory;
use App\Models\CostItem;
use App\Models\ManualOverride;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Schritt 8 des geführten Ablaufs: Verteilerschlüssel und Verbrauch.
 *
 * VERBINDLICHE REGELN
 *
 *  1. Vorschlagspriorität: ausdrücklich bestätigte Mietvertragsregelung, dann
 *     bestätigter Schlüssel aus dem Vorjahr, dann fachlich naheliegender
 *     Standardwert mit Warnhinweis. Die Quelle wird als Badge ausgewiesen.
 *  2. Live-Validierung: Die Summe der Anteile muss 100,00 Prozent ergeben.
 *     Fehlende Werte je Einheit werden rot UND als Text markiert.
 *  3. Abweichungen von der Mietvertragsregelung erzeugen eine Warnung, keinen
 *     stillschweigenden Gleichlauf. WEG-Schlüssel und mietvertraglicher
 *     Umlageschlüssel werden nicht gleichgesetzt.
 *  4. Verbrauch bei Nutzerwechsel ohne Zwischenablesung: keine stille
 *     Schätzung. Eine Ersatzverteilung ist ausdrücklich zu bestätigen und wird
 *     protokolliert.
 *  5. Dezimalwerte werden als Zeichenkette geführt und mit brick/math
 *     gerechnet, niemals als float (ADR-004).
 */
final class AllocationKeyWorkspace
{
    public const string AUDIT_ACTION = 'billing_run.allocation_keys_saved';

    public const string AUDIT_SUBSTITUTE = 'billing_run.substitute_distribution_confirmed';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return list<AllocationKeyRow>
     */
    public function rows(BillingRun $billingRun): array
    {
        $billingRun->loadMissing([
            'property.units.tenancies',
            'costItems.costCategory',
            'allocationKeys.values',
        ]);

        $rows = [];

        foreach ($this->categories($billingRun) as $category) {
            $rows[] = $this->row($billingRun, $category);
        }

        return $rows;
    }

    /**
     * Kostenarten des Laufs. Heizkostenarten entfallen bei dezentraler
     * Versorgung (Fall C), weil keine Heizkosten angesetzt werden.
     *
     * @return list<CostCategory>
     */
    public function categories(BillingRun $billingRun): array
    {
        $decentralized = $billingRun->heating_supply_case === HeatingSupplyCase::DEZENTRAL;

        /** @var array<string, CostCategory> $categories */
        $categories = [];

        foreach ($billingRun->costItems as $item) {
            if ($item->status === CostItemStatus::VERWORFEN) {
                continue;
            }

            $category = $item->costCategory;

            if ($category === null) {
                continue;
            }

            if ($decentralized && ($category->is_heating_related || $category->is_warm_water_related)) {
                continue;
            }

            $categories[(string) $category->getKey()] = $category;
        }

        $sorted = $categories;
        uasort($sorted, static fn (CostCategory $a, CostCategory $b): int => $a->sort_order <=> $b->sort_order
            ?: strcmp($a->name, $b->name));

        return array_values($sorted);
    }

    private function row(BillingRun $billingRun, CostCategory $category): AllocationKeyRow
    {
        $categoryId = (string) $category->getKey();
        $record = $this->storedKey($billingRun, $categoryId);
        $contractType = $this->contractKeyType($billingRun, $categoryId);

        if ($record instanceof AllocationKey) {
            $type = $record->key_type;
            $source = $record->source;
        } else {
            [$type, $source] = $this->suggest($billingRun, $category, $contractType);
        }

        $scope = BillingRunInputAssembler::scopeOf($type);
        $isUnitScope = $scope === AllocationKeyScope::UNIT;

        $values = $isUnitScope
            ? $this->unitValues($billingRun, $record, $type)
            : $this->occupancyValues($billingRun, $record);

        $sum = $this->sum($values);
        $denominator = $this->denominator($record, $sum);

        return new AllocationKeyRow(
            $categoryId,
            $category->name,
            $type,
            $source,
            $values,
            GermanNumberFormatter::decimal($denominator, 2),
            $this->percent($sum, $denominator),
            $isUnitScope,
            $contractType,
            $source === AllocationKeySource::DEFAULT
                ? sprintf(
                    'Für %s ist kein bestätigter Schlüssel hinterlegt. Vorgeschlagen ist der fachlich '
                    .'naheliegende Standardwert %s. Bitte prüfen Sie die Mietvertragsregelung.',
                    $category->name,
                    $type->label()
                )
                : null,
            $record?->measurement_unit,
            $type === AllocationKeyType::VERBRAUCH && $this->needsSubstituteConfirmation($billingRun),
        );
    }

    /**
     * Vorschlag nach der verbindlichen Priorität.
     *
     * @return array{0: AllocationKeyType, 1: AllocationKeySource}
     */
    private function suggest(
        BillingRun $billingRun,
        CostCategory $category,
        ?AllocationKeyType $contractType,
    ): array {
        if ($contractType instanceof AllocationKeyType) {
            return [$contractType, AllocationKeySource::MIETVERTRAG];
        }

        $previous = $this->previousYearKeyType($billingRun, (string) $category->getKey());

        if ($previous instanceof AllocationKeyType) {
            return [$previous, AllocationKeySource::VORJAHR];
        }

        return [$category->default_allocation_key_type, AllocationKeySource::DEFAULT];
    }

    /**
     * Ausdrücklich bestätigte Mietvertragsregelung dieses Laufs.
     */
    public function contractKeyType(BillingRun $billingRun, string $categoryId): ?AllocationKeyType
    {
        foreach ($billingRun->allocationKeys as $key) {
            if ($key->cost_category_id !== $categoryId) {
                continue;
            }

            if ($key->source === AllocationKeySource::MIETVERTRAG && $key->confirmed_at instanceof Carbon) {
                return $key->key_type;
            }
        }

        return null;
    }

    private function previousYearKeyType(BillingRun $billingRun, string $categoryId): ?AllocationKeyType
    {
        $previousId = $billingRun->getAttribute('previous_billing_run_id');

        if (! is_string($previousId) || $previousId === '') {
            return null;
        }

        $key = AllocationKey::query()
            ->where('billing_run_id', $previousId)
            ->where('cost_category_id', $categoryId)
            ->whereNotNull('confirmed_at')
            ->orderBy('created_at')
            ->first();

        return $key instanceof AllocationKey ? $key->key_type : null;
    }

    private function storedKey(BillingRun $billingRun, string $categoryId): ?AllocationKey
    {
        $found = null;

        foreach ($billingRun->allocationKeys as $key) {
            if ($key->cost_category_id !== $categoryId || $key->cost_item_id !== null) {
                continue;
            }

            if ($key->source === AllocationKeySource::MANUELL) {
                return $key;
            }

            $found ??= $key;
        }

        return $found;
    }

    /**
     * @return list<AllocationValueRow>
     */
    private function unitValues(BillingRun $billingRun, ?AllocationKey $record, AllocationKeyType $type): array
    {
        $stored = [];

        if ($record instanceof AllocationKey) {
            foreach ($record->values as $value) {
                if ($value->unit_id !== null) {
                    $stored[$value->unit_id] = [$value->numerator, $value->source->label()];
                }
            }
        }

        $rows = [];

        foreach ($this->units($billingRun) as $unit) {
            $unitId = (string) $unit->getKey();
            $entry = $stored[$unitId] ?? null;
            $value = $entry[0] ?? $this->masterValue($unit, $type);

            $rows[] = new AllocationValueRow(
                $unitId,
                $unit->label,
                is_string($value) && trim($value) !== '' ? trim($value) : null,
                true,
                $entry[1] ?? ($value === null ? null : ValueSource::MANUELL->label()),
            );
        }

        return $rows;
    }

    /**
     * @return list<AllocationValueRow>
     */
    private function occupancyValues(BillingRun $billingRun, ?AllocationKey $record): array
    {
        $stored = [];

        if ($record instanceof AllocationKey) {
            foreach ($record->values as $value) {
                if ($value->tenancy_id !== null) {
                    $stored[$value->tenancy_id] = [$value->numerator, $value->source->label()];
                }
            }
        }

        $period = new DatePeriodRange($billingRun->period_start, $billingRun->period_end);
        $rows = [];

        foreach ($this->units($billingRun) as $unit) {
            foreach ($unit->tenancies as $tenancy) {
                if (! $this->overlaps($tenancy, $period)) {
                    continue;
                }

                $tenancyId = (string) $tenancy->getKey();
                $entry = $stored[$tenancyId] ?? null;

                $rows[] = new AllocationValueRow(
                    $tenancyId,
                    sprintf('%s, %s', $unit->label, $tenancy->tenant_display_name),
                    is_string($entry[0] ?? null) ? trim((string) $entry[0]) : null,
                    false,
                    $entry[1] ?? null,
                );
            }
        }

        return $rows;
    }

    private function masterValue(Unit $unit, AllocationKeyType $type): ?string
    {
        $value = match ($type) {
            AllocationKeyType::WOHNFLAECHE => $unit->living_area_sqm,
            AllocationKeyType::BEHEIZTE_WOHNFLAECHE => $unit->heated_area_sqm,
            AllocationKeyType::MEA => $unit->mea,
            AllocationKeyType::EINHEITEN => '1',
            AllocationKeyType::INDIVIDUELL_1 => $unit->individual_key_1_value,
            AllocationKeyType::INDIVIDUELL_2 => $unit->individual_key_2_value,
            AllocationKeyType::INDIVIDUELL_3 => $unit->individual_key_3_value,
            AllocationKeyType::INDIVIDUELL_4 => $unit->individual_key_4_value,
            AllocationKeyType::INDIVIDUELL_5 => $unit->individual_key_5_value,
            default => null,
        };

        return is_string($value) ? $value : null;
    }

    /**
     * Speichert die Auswahl und die Werte je Beteiligtem.
     *
     * @param  array<string, array{key_type?: string, nenner?: string|null, masseinheit?: string|null, werte?: array<string, string|null>}>  $eingaben
     */
    public function save(BillingRun $billingRun, User $actor, array $eingaben): int
    {
        $rows = $this->rows($billingRun);
        $gespeichert = 0;

        DB::transaction(function () use ($billingRun, $actor, $eingaben, $rows, &$gespeichert): void {
            foreach ($rows as $row) {
                $eingabe = $eingaben[$row->categoryId] ?? null;

                if ($eingabe === null) {
                    continue;
                }

                $type = AllocationKeyType::tryFrom((string) ($eingabe['key_type'] ?? '')) ?? $row->keyType;

                AllocationKey::query()
                    ->where('billing_run_id', $billingRun->getKey())
                    ->where('cost_category_id', $row->categoryId)
                    ->whereNull('cost_item_id')
                    ->where('source', AllocationKeySource::MANUELL->value)
                    ->delete();

                $nenner = $eingabe['nenner'] ?? null;

                /** @var AllocationKey $key */
                $key = AllocationKey::query()->create([
                    'organization_id' => $billingRun->getAttribute('organization_id'),
                    'billing_run_id' => $billingRun->getKey(),
                    'cost_category_id' => $row->categoryId,
                    'key_type' => $type,
                    'source' => AllocationKeySource::MANUELL,
                    'denominator' => is_string($nenner) && trim($nenner) !== '' ? $this->normalize($nenner) : null,
                    'measurement_unit' => is_string($eingabe['masseinheit'] ?? null) ? $eingabe['masseinheit'] : null,
                    'label' => $type->label(),
                    'confirmed_by_user_id' => $actor->getKey(),
                    'confirmed_at' => Carbon::now(),
                    'note' => $row->deviatesFromContract()
                        ? 'Der Schlüssel weicht von der bestätigten Mietvertragsregelung ab.'
                        : null,
                ]);

                $isUnitScope = BillingRunInputAssembler::scopeOf($type) === AllocationKeyScope::UNIT;

                foreach ($eingabe['werte'] ?? [] as $participantId => $wert) {
                    if (! is_string($wert) || trim($wert) === '') {
                        continue;
                    }

                    AllocationKeyValue::query()->create([
                        'organization_id' => $billingRun->getAttribute('organization_id'),
                        'allocation_key_id' => $key->getKey(),
                        'unit_id' => $isUnitScope ? (string) $participantId : null,
                        'tenancy_id' => $isUnitScope ? null : (string) $participantId,
                        'numerator' => $this->normalize($wert),
                        'source' => ValueSource::MANUELL,
                    ]);
                }

                $gespeichert++;
            }
        });

        $organizationId = $billingRun->getAttribute('organization_id');

        $this->audit->record(
            action: self::AUDIT_ACTION,
            subject: $billingRun,
            actor: $actor,
            organization: is_string($organizationId) ? $organizationId : null,
            metadata: ['kostenarten' => $gespeichert],
        );

        return $gespeichert;
    }

    /**
     * Ausdrücklich bestätigte Ersatzverteilung ohne Zwischenablesung.
     */
    public function confirmSubstituteDistribution(BillingRun $billingRun, string $unitId, User $actor): void
    {
        $organizationId = $billingRun->getAttribute('organization_id');

        ManualOverride::query()->create([
            'organization_id' => $organizationId,
            'billing_run_id' => $billingRun->getKey(),
            'user_id' => $actor->getKey(),
            'subject_type' => Unit::class,
            'subject_id' => $unitId,
            'field' => BillingRunInputAssembler::SUBSTITUTE_FIELD,
            'old_value' => null,
            'new_value' => ['bestaetigt' => true],
            'reason' => 'Es liegt keine Zwischenablesung vor. Die Ersatzverteilung wurde ausdrücklich bestätigt '
                .'und wird in der Abrechnung gekennzeichnet.',
            'occurred_at' => Carbon::now(),
        ]);

        $this->audit->record(
            action: self::AUDIT_SUBSTITUTE,
            subject: $billingRun,
            actor: $actor,
            organization: is_string($organizationId) ? $organizationId : null,
            metadata: ['einheit' => $unitId],
        );
    }

    /**
     * Einheiten mit mehr als einem Nutzungszeitraum, für die noch keine
     * Bestätigung vorliegt.
     *
     * @return list<Unit>
     */
    public function unitsNeedingSubstituteConfirmation(BillingRun $billingRun): array
    {
        $billingRun->loadMissing('property.units.tenancies');

        $period = new DatePeriodRange($billingRun->period_start, $billingRun->period_end);

        /** @var list<string> $confirmed */
        $confirmed = ManualOverride::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('subject_type', Unit::class)
            ->where('field', BillingRunInputAssembler::SUBSTITUTE_FIELD)
            ->pluck('subject_id')
            ->map(static fn (mixed $id): string => is_string($id) ? $id : '')
            ->all();

        $units = [];

        foreach ($this->units($billingRun) as $unit) {
            $count = 0;

            foreach ($unit->tenancies as $tenancy) {
                if ($this->overlaps($tenancy, $period)) {
                    $count++;
                }
            }

            if ($count > 1 && ! in_array((string) $unit->getKey(), $confirmed, true)) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    private function needsSubstituteConfirmation(BillingRun $billingRun): bool
    {
        return $this->unitsNeedingSubstituteConfirmation($billingRun) !== [];
    }

    /**
     * Gründe, die das Weitergehen verhindern.
     *
     * @return list<string>
     */
    public function blockingReasons(BillingRun $billingRun): array
    {
        $reasons = [];

        foreach ($this->rows($billingRun) as $row) {
            foreach ($row->missingValues() as $missing) {
                $reasons[] = sprintf(
                    'Kostenart %s: %s',
                    $row->categoryLabel,
                    (string) $missing->missingText()
                );
            }

            $shareWarning = $row->shareWarning();

            if ($shareWarning !== null) {
                $reasons[] = sprintf('Kostenart %s: %s', $row->categoryLabel, $shareWarning);
            }

            if ($row->consumptionNeedsSubstitute) {
                $reasons[] = sprintf(
                    'Kostenart %s: Für eine Einheit mit Nutzerwechsel liegt keine Zwischenablesung vor. Bitte '
                    .'bestätigen Sie die Ersatzverteilung ausdrücklich.',
                    $row->categoryLabel
                );
            }
        }

        return $reasons;
    }

    /**
     * Warnungen, die das Weitergehen nicht verhindern.
     *
     * @return list<string>
     */
    public function warnings(BillingRun $billingRun): array
    {
        $warnings = [];

        foreach ($this->rows($billingRun) as $row) {
            $deviation = $row->deviationWarning();

            if ($deviation !== null) {
                $warnings[] = sprintf('Kostenart %s: %s', $row->categoryLabel, $deviation);
            }

            if ($row->defaultWarning !== null) {
                $warnings[] = $row->defaultWarning;
            }
        }

        return $warnings;
    }

    public function isComplete(BillingRun $billingRun): bool
    {
        return $this->blockingReasons($billingRun) === [];
    }

    /**
     * @param  list<AllocationValueRow>  $values
     */
    private function sum(array $values): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ($values as $value) {
            if ($value->isMissing()) {
                continue;
            }

            $sum = $sum->plus(BigDecimal::of($this->normalize((string) $value->value)));
        }

        return $sum;
    }

    private function denominator(?AllocationKey $record, BigDecimal $sum): BigDecimal
    {
        $stored = $record?->denominator;

        if (is_string($stored) && trim($stored) !== '') {
            $value = BigDecimal::of($this->normalize($stored));

            if (! $value->isZero()) {
                return $value;
            }
        }

        return $sum->isZero() ? BigDecimal::one() : $sum;
    }

    /**
     * Summe der Anteile in Prozent, deutsche Schreibweise mit zwei
     * Dezimalstellen.
     */
    private function percent(BigDecimal $sum, BigDecimal $denominator): string
    {
        if ($denominator->isZero()) {
            return '0,00';
        }

        $percent = $sum->multipliedBy(100)->dividedBy($denominator, 2, RoundingMode::HALF_UP);

        return GermanNumberFormatter::decimal($percent, 2);
    }

    private function normalize(string $value): string
    {
        return str_replace(',', '.', trim($value));
    }

    private function overlaps(Tenancy $tenancy, DatePeriodRange $period): bool
    {
        $end = $tenancy->ends_on instanceof Carbon ? $tenancy->ends_on : $period->end;

        if ($end < $tenancy->starts_on) {
            return false;
        }

        return $period->intersect(new DatePeriodRange($tenancy->starts_on, $end)) instanceof DatePeriodRange;
    }

    /**
     * @return list<Unit>
     */
    private function units(BillingRun $billingRun): array
    {
        $units = $billingRun->property->units
            ->sortBy(static fn (Unit $unit): string => $unit->label)
            ->values()
            ->all();

        /** @var list<Unit> $units */
        return $units;
    }

    /**
     * Alle auswählbaren Schlüsseltypen, einschließlich beheizte Wohnfläche,
     * Personentage und individuelle Schlüssel 1 bis 5.
     *
     * @return list<AllocationKeyType>
     */
    public static function selectableTypes(): array
    {
        return AllocationKeyType::cases();
    }

    /**
     * Kostenpositionen einer Kostenart, damit die Oberfläche den Bezug zeigen
     * kann.
     *
     * @return list<CostItem>
     */
    public function itemsOfCategory(BillingRun $billingRun, string $categoryId): array
    {
        $items = $billingRun->costItems
            ->filter(static fn (CostItem $item): bool => $item->cost_category_id === $categoryId
                && $item->status !== CostItemStatus::VERWORFEN)
            ->values()
            ->all();

        /** @var list<CostItem> $items */
        return $items;
    }
}
