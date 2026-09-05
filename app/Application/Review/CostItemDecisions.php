<?php

declare(strict_types=1);

namespace App\Application\Review;

use App\Application\Account\AuditRecorder;
use App\Application\Wizard\PreviewBuilder;
use App\Application\Wizard\ReviewConfirmation;
use App\Enums\ApportionmentStatus;
use App\Enums\BillingRunStatus;
use App\Enums\CostItemSource;
use App\Enums\CostItemStatus;
use App\Enums\Paragraph35aType;
use App\Models\BillingRun;
use App\Models\CostCategory;
use App\Models\CostItem;
use App\Models\ManualOverride;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Nutzerentscheidungen der Kostenpruefung (Schritt 6).
 *
 * Zulaessige Aktionen: bestaetigen, bearbeiten, ausschliessen, einer Einheit
 * direkt zuordnen, verwerfen und manuell eine neue Position anlegen.
 *
 * VERBINDLICH
 *
 *  1. Eine Bestaetigung ist immer eine ausdrueckliche Nutzerhandlung und wird
 *     mit Nutzer und Zeitpunkt festgehalten.
 *  2. Die Aufnahme einer nicht umlagefaehigen oder pruefpflichtigen Position
 *     in die Umlage erfordert eine Begruendung. Die Begruendung wird
 *     gespeichert und ist keine juristische Freigabe.
 *  3. Ein Betrag wird nie automatisch veraendert. Eine Korrektur ist immer
 *     eine bewusste Eingabe des Nutzers.
 *  4. Jede Entscheidung ist abrechnungsrelevant. Eine bestehende Vorschau
 *     wird deshalb ungueltig und eine bereits erteilte Bestaetigung wird
 *     zurueckgenommen (PreviewBuilder Regel 3). Das geschieht zentral hier,
 *     damit kein Aufrufer es vergessen kann.
 */
final class CostItemDecisions
{
    public const string AUDIT_DIRECT_UNIT_REMOVED = 'cost_item.direct_unit_removed';

    public function __construct(
        private readonly PreviewBuilder $preview,
        private readonly ReviewConfirmation $confirmation,
        private readonly AuditRecorder $audit,
    ) {}

    public function confirm(BillingRun $billingRun, CostItem $item, User $user): CostItem
    {
        $item->forceFill([
            'status' => CostItemStatus::BESTAETIGT,
            'confirmed_by_user_id' => $user->getKey(),
            'confirmed_at' => Carbon::now(),
        ])->save();

        $this->invalidatePreview($billingRun);

        return $item;
    }

    public function discard(BillingRun $billingRun, CostItem $item, User $user): CostItem
    {
        $item->forceFill([
            'status' => CostItemStatus::VERWORFEN,
            'excluded_from_apportionment' => true,
            'confirmed_by_user_id' => $user->getKey(),
            'confirmed_at' => Carbon::now(),
        ])->save();

        $this->invalidatePreview($billingRun);

        return $item;
    }

    /**
     * Von der Umlage ausschliessen, die Position aber in der Aufstellung
     * behalten. Sie gilt damit als entschieden.
     */
    public function exclude(BillingRun $billingRun, CostItem $item, User $user, ?string $reason = null): CostItem
    {
        $item->forceFill([
            'status' => CostItemStatus::BESTAETIGT,
            'excluded_from_apportionment' => true,
            'apportionment_status' => ApportionmentStatus::NICHT_UMLAGEFAEHIG,
            'apportionment_override_reason' => $reason,
            'confirmed_by_user_id' => $user->getKey(),
            'confirmed_at' => Carbon::now(),
        ])->save();

        $this->invalidatePreview($billingRun);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BillingRun $billingRun, CostItem $item, User $user, array $data): CostItem
    {
        $attributes = [];

        if (isset($data['description']) && is_string($data['description'])) {
            $attributes['description'] = mb_substr($data['description'], 0, 190);
        }

        if (isset($data['supplier_name'])) {
            $attributes['supplier_name'] = is_string($data['supplier_name']) && $data['supplier_name'] !== ''
                ? mb_substr($data['supplier_name'], 0, 190)
                : null;
        }

        if (isset($data['amount_cent']) && is_numeric($data['amount_cent'])) {
            $attributes['amount_cent'] = (int) $data['amount_cent'];
        }

        foreach (['document_date', 'service_period_start', 'service_period_end'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $attributes[$field] = is_string($value) && $value !== '' ? $value : null;
            }
        }

        if (array_key_exists('labor_share_cent', $data)) {
            $value = $data['labor_share_cent'];
            $attributes['labor_share_cent'] = is_numeric($value) ? (int) $value : null;
        }

        if (array_key_exists('cost_category_id', $data)) {
            $category = $this->categoryFor($billingRun, $data['cost_category_id']);

            $attributes['cost_category_id'] = $category?->getKey();

            if ($category instanceof CostCategory) {
                $status = $category->getAttribute('apportionment_status');
                $attributes['apportionment_status'] = $status instanceof ApportionmentStatus
                    ? $status
                    : ApportionmentStatus::PRUEFPFLICHTIG;
                $attributes['excluded_from_apportionment'] =
                    $category->getAttribute('excluded_from_apportionment_by_default') === true
                    || $status === ApportionmentStatus::NICHT_UMLAGEFAEHIG;
                $attributes['is_heating_cost'] = $category->getAttribute('is_heating_related') === true;
                $attributes['is_warm_water_cost'] = $category->getAttribute('is_warm_water_related') === true;

                $type = $category->getAttribute('paragraph_35a_type');
                $attributes['paragraph_35a_type'] = $type instanceof Paragraph35aType
                    ? $type
                    : Paragraph35aType::NONE;
            }
        }

        // Die bewusste Aufnahme einer nicht umlagefaehigen Position erfordert
        // eine Begruendung. Ohne Begruendung bleibt der Ausschluss bestehen.
        if (isset($data['include_despite_status']) && $data['include_despite_status'] === true) {
            $reason = $data['apportionment_override_reason'] ?? null;

            if (is_string($reason) && trim($reason) !== '') {
                $attributes['excluded_from_apportionment'] = false;
                $attributes['apportionment_override_reason'] = mb_substr(trim($reason), 0, 1000);
            }
        }

        if (array_key_exists('direct_unit_id', $data)) {
            $attributes['direct_unit_id'] = $this->unitFor($billingRun, $data['direct_unit_id'])?->getKey();
        }

        $item->forceFill($attributes)->save();

        $this->invalidatePreview($billingRun);

        return $item;
    }

    /**
     * Direkte Zuordnung zu einer Einheit.
     */
    public function assignToUnit(BillingRun $billingRun, CostItem $item, User $user, ?string $unitId): CostItem
    {
        $unit = $this->unitFor($billingRun, $unitId);

        $item->forceFill([
            'direct_unit_id' => $unit?->getKey(),
        ])->save();

        $this->invalidatePreview($billingRun);

        return $item;
    }

    /**
     * Die Zieleinheit einer Direktzuordnung wurde entfernt (Befund R5).
     *
     * Ohne diesen Schritt bliebe die Position auf eine nicht mehr vorhandene
     * Einheit gerichtet und fiele nach dem endgueltigen Entfernen der Einheit
     * (direct_unit_id wird von der Datenbank auf null gesetzt) still auf den
     * Kategorieschluessel zurueck. Stattdessen wird die Zuordnung geloest, die
     * Position wieder pruefpflichtig gesetzt (Status VORGESCHLAGEN) und der
     * Vorgang als ManualOverride und im Revisionsprotokoll festgehalten.
     * Laeufe, deren Berechnungsstand gesperrt ist (bezahlt, in Finalisierung,
     * finalisiert, abgebrochen), bleiben unangetastet.
     *
     * @return int Anzahl der gekennzeichneten Positionen
     */
    public function markDirectUnitRemoved(Unit $unit, ?User $user = null): int
    {
        /** @var list<CostItem> $items */
        $items = CostItem::query()
            ->where('direct_unit_id', $unit->getKey())
            ->whereHas('billingRun', static function ($query): void {
                // FAILED ist ein bezahlter Lauf, dessen Finalisierung
                // scheiterte; sein Snapshot ist gesperrt und wird nicht
                // mehr aus den Kostenpositionen aufgebaut.
                $query->whereNotIn('status', [
                    BillingRunStatus::PAID->value,
                    BillingRunStatus::FINALIZING->value,
                    BillingRunStatus::FINALIZED->value,
                    BillingRunStatus::FAILED->value,
                    BillingRunStatus::CANCELLED->value,
                ]);
            })
            ->with('billingRun')
            ->get()
            ->all();

        $reason = sprintf(
            'Die Zieleinheit %s der Direktzuordnung wurde entfernt. Die Position ist erneut zu pruefen; '
            .'sie wird nicht auf den Schluessel der Kostenart umverteilt.',
            $unit->label
        );

        $touchedRuns = [];

        foreach ($items as $item) {
            // Eine verworfene Position bleibt verworfen: die Entscheidung des
            // Nutzers gilt weiter, nur die Zuordnung zur Einheit wird geloest.
            // Vorgeschlagene und bestaetigte Positionen werden erneut
            // pruefpflichtig, weil ihr Verteilungsziel entfallen ist.
            $bleibtVerworfen = $item->status === CostItemStatus::VERWORFEN;

            $item->forceFill($bleibtVerworfen
                ? ['direct_unit_id' => null]
                : [
                    'direct_unit_id' => null,
                    'status' => CostItemStatus::VORGESCHLAGEN,
                    'confirmed_by_user_id' => null,
                    'confirmed_at' => null,
                ])->save();

            ManualOverride::query()->create([
                'organization_id' => $item->organization_id,
                'billing_run_id' => $item->billing_run_id,
                'user_id' => $user?->getKey(),
                'subject_type' => CostItem::class,
                'subject_id' => $item->getKey(),
                'field' => 'direct_unit_id',
                'old_value' => ['einheit' => (string) $unit->getKey(), 'bezeichnung' => $unit->label],
                'new_value' => null,
                'reason' => $reason,
                'occurred_at' => Carbon::now(),
            ]);

            $this->audit->record(
                action: self::AUDIT_DIRECT_UNIT_REMOVED,
                subject: $item,
                actor: $user,
                organization: $item->organization_id,
                metadata: ['einheit' => (string) $unit->getKey(), 'position' => $item->description],
                reason: $reason,
            );

            $run = $item->billingRun;
            $touchedRuns[(string) $run->getKey()] = $run;
        }

        foreach ($touchedRuns as $run) {
            $this->invalidatePreview($run);
        }

        return count($items);
    }

    /**
     * Manuell erfasste Position. Sie ist damit vom Nutzer erfasst, aber noch
     * nicht bestaetigt; die Bestaetigung bleibt eine eigene Handlung.
     *
     * @param  array<string, mixed>  $data
     */
    public function createManual(BillingRun $billingRun, User $user, array $data): CostItem
    {
        $category = $this->categoryFor($billingRun, $data['cost_category_id'] ?? null);

        $status = $category?->getAttribute('apportionment_status');
        $status = $status instanceof ApportionmentStatus ? $status : ApportionmentStatus::PRUEFPFLICHTIG;

        $type = $category?->getAttribute('paragraph_35a_type');

        $item = new CostItem;

        $item->fill([
            'organization_id' => $billingRun->getAttribute('organization_id'),
            'billing_run_id' => $billingRun->getKey(),
            'cost_category_id' => $category?->getKey(),
            'document_id' => null,
            'description' => mb_substr(is_string($data['description'] ?? null) ? $data['description'] : 'Manuelle Position', 0, 190),
            'supplier_name' => is_string($data['supplier_name'] ?? null) && $data['supplier_name'] !== ''
                ? mb_substr($data['supplier_name'], 0, 190)
                : null,
            'invoice_number' => is_string($data['invoice_number'] ?? null) && $data['invoice_number'] !== ''
                ? mb_substr($data['invoice_number'], 0, 80)
                : null,
            'amount_cent' => is_numeric($data['amount_cent'] ?? null) ? (int) $data['amount_cent'] : 0,
            'document_date' => is_string($data['document_date'] ?? null) && $data['document_date'] !== ''
                ? $data['document_date']
                : null,
            'service_period_start' => is_string($data['service_period_start'] ?? null) && $data['service_period_start'] !== ''
                ? $data['service_period_start']
                : null,
            'service_period_end' => is_string($data['service_period_end'] ?? null) && $data['service_period_end'] !== ''
                ? $data['service_period_end']
                : null,
            'source' => CostItemSource::MANUELL,
            'status' => CostItemStatus::VORGESCHLAGEN,
            'apportionment_status' => $status,
            'excluded_from_apportionment' => $category?->getAttribute('excluded_from_apportionment_by_default') === true
                || $status === ApportionmentStatus::NICHT_UMLAGEFAEHIG,
            'labor_share_cent' => is_numeric($data['labor_share_cent'] ?? null) ? (int) $data['labor_share_cent'] : null,
            'paragraph_35a_type' => $type instanceof Paragraph35aType ? $type : Paragraph35aType::NONE,
            'is_heating_cost' => $category?->getAttribute('is_heating_related') === true,
            'is_warm_water_cost' => $category?->getAttribute('is_warm_water_related') === true,
            'direct_unit_id' => $this->unitFor($billingRun, $data['direct_unit_id'] ?? null)?->getKey(),
            // Eine manuelle Eingabe hat keine maschinelle Konfidenz.
            'confidence' => null,
            'source_page' => null,
        ]);

        $item->save();

        $this->invalidatePreview($billingRun);

        return $item;
    }

    /**
     * Eine Aenderung an den Kostenpositionen entzieht Vorschau und
     * Bestaetigung die Grundlage.
     */
    private function invalidatePreview(BillingRun $billingRun): void
    {
        $this->preview->invalidate($billingRun);
        $this->confirmation->reset($billingRun);
    }

    private function categoryFor(BillingRun $billingRun, mixed $categoryId): ?CostCategory
    {
        if (! is_string($categoryId) || $categoryId === '') {
            return null;
        }

        $category = CostCategory::query()
            ->whereKey($categoryId)
            ->where(function ($query) use ($billingRun): void {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $billingRun->getAttribute('organization_id'));
            })
            ->first();

        return $category instanceof CostCategory ? $category : null;
    }

    private function unitFor(BillingRun $billingRun, mixed $unitId): ?Unit
    {
        if (! is_string($unitId) || $unitId === '') {
            return null;
        }

        $unit = Unit::query()
            ->whereKey($unitId)
            ->where('organization_id', $billingRun->getAttribute('organization_id'))
            ->where('property_id', $billingRun->getAttribute('property_id'))
            ->first();

        return $unit instanceof Unit ? $unit : null;
    }
}
