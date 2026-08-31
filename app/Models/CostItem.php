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
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kostenposition eines Abrechnungslaufs. Betraege ausschliesslich in Cent.
 *
 * Nicht umlagefaehige und pruefpflichtige Positionen sind standardmaessig
 * ausgeschlossen. Eine Abweichung erfordert apportionment_override_reason, wird in
 * ManualOverride versioniert und ist keine juristische Freigabe.
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
