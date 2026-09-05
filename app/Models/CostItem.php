<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationKeyType;
use App\Enums\ApportionmentStatus;
use App\Enums\CostItemSource;
use App\Enums\CostItemStatus;
use App\Enums\Paragraph35aType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CostItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Kostenposition eines Abrechnungslaufs. Betraege ausschliesslich in Cent.
 *
 * Nicht umlagefaehige und pruefpflichtige Positionen sind standardmaessig
 * ausgeschlossen. Eine Abweichung erfordert apportionment_override_reason, wird in
 * ManualOverride versioniert und ist keine juristische Freigabe.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $billing_run_id
 * @property string|null $cost_category_id
 * @property string|null $document_id
 * @property string $description
 * @property string|null $supplier_name
 * @property string|null $invoice_number
 * @property int $amount_cent
 * @property int|null $net_amount_cent
 * @property int|null $vat_amount_cent
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $vat_rate_percent
 * @property Carbon|null $document_date
 * @property Carbon|null $service_period_start
 * @property Carbon|null $service_period_end
 * @property CostItemSource $source
 * @property CostItemStatus $status
 * @property ApportionmentStatus $apportionment_status
 * @property bool $excluded_from_apportionment
 * @property string|null $apportionment_override_reason
 * @property AllocationKeyType|null $allocation_key_type
 * @property string|null $direct_unit_id
 * @property string|null $direct_tenancy_id
 * @property int|null $labor_share_cent
 * @property Paragraph35aType $paragraph_35a_type
 * @property bool $is_heating_cost
 * @property bool $is_warm_water_cost
 * @property string|null $duplicate_of_cost_item_id
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $duplicate_confidence
 * @property string|null $confidence
 * @property int|null $source_page
 * @property int|null $prior_year_amount_cent
 * @property string|null $confirmed_by_user_id
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AllocationKey> $allocationKeys
 * @property-read BillingRun $billingRun
 * @property-read User|null $confirmedBy
 * @property-read CostCategory|null $costCategory
 * @property-read Tenancy|null $directTenancy
 * @property-read Unit|null $directUnit
 * @property-read Document|null $document
 * @property-read CostItem|null $duplicateOf
 * @property-read Organization $organization
 */
class CostItem extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<CostItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cent' => 'integer',
            'net_amount_cent' => 'integer',
            'vat_amount_cent' => 'integer',
            'vat_rate_percent' => 'decimal:4',
            'document_date' => 'date',
            'service_period_start' => 'date',
            'service_period_end' => 'date',
            'source' => CostItemSource::class,
            'status' => CostItemStatus::class,
            'apportionment_status' => ApportionmentStatus::class,
            'excluded_from_apportionment' => 'boolean',
            'allocation_key_type' => AllocationKeyType::class,
            'labor_share_cent' => 'integer',
            'paragraph_35a_type' => Paragraph35aType::class,
            'is_heating_cost' => 'boolean',
            'is_warm_water_cost' => 'boolean',
            'duplicate_confidence' => 'decimal:4',
            'confidence' => 'decimal:4',
            'source_page' => 'integer',
            'prior_year_amount_cent' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BillingRun, $this>
     */
    public function billingRun(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class);
    }

    /**
     * @return BelongsTo<CostCategory, $this>
     */
    public function costCategory(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function directUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'direct_unit_id');
    }

    /**
     * @return BelongsTo<Tenancy, $this>
     */
    public function directTenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class, 'direct_tenancy_id');
    }

    /**
     * @return BelongsTo<CostItem, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_cost_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /**
     * @return HasMany<AllocationKey, $this>
     */
    public function allocationKeys(): HasMany
    {
        return $this->hasMany(AllocationKey::class);
    }

    /**
     * Position wird tatsaechlich auf Mieter verteilt.
     */
    public function isApportioned(): bool
    {
        return $this->getAttribute('status') === CostItemStatus::BESTAETIGT
            && $this->getAttribute('excluded_from_apportionment') === false
            && $this->getAttribute('apportionment_status') === ApportionmentStatus::UMLAGEFAEHIG;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeApportionable(Builder $query): void
    {
        $query->where('excluded_from_apportionment', false)
            ->where('apportionment_status', ApportionmentStatus::UMLAGEFAEHIG->value)
            ->where('status', CostItemStatus::BESTAETIGT->value);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeNeedsDecision(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner->where('status', CostItemStatus::VORGESCHLAGEN->value)
                ->orWhere('apportionment_status', ApportionmentStatus::PRUEFPFLICHTIG->value);
        });
    }
}
