<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationKeyType;
use App\Enums\ApportionmentStatus;
use App\Enums\Paragraph35aType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kostenkategorie mit BetrKV-Referenz, Umlagestatus, Defaultschluessel und
 * Paragraf-35a-Typ.
 *
 * Jede Kategorie ist ueber valid_from und valid_to versioniert. Eine Kostenposition
 * verweist auf die zum Abrechnungszeitraum gueltige Fassung, damit alte
 * Abrechnungen nach Gesetzesaenderungen reproduzierbar bleiben.
 *
 * Die Umlagebewertung ist ein fachlicher Vorschlag und keine Rechtsfreigabe.
 */
class CostCategory extends Model
{
    /** @use HasFactory<\Database\Factories\CostCategoryFactory> */
    use HasFactory;

    use HasUlids;

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
            'apportionment_status' => ApportionmentStatus::class,
            'default_allocation_key_type' => AllocationKeyType::class,
            'paragraph_35a_type' => Paragraph35aType::class,
            'excluded_from_apportionment_by_default' => 'boolean',
            'requires_contract_basis' => 'boolean',
            'requires_manual_review' => 'boolean',
            'is_heating_related' => 'boolean',
            'is_warm_water_related' => 'boolean',
            'supports_labor_share' => 'boolean',
            'is_custom' => 'boolean',
            'sort_order' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<CostItem, $this>
     */
    public function costItems(): HasMany
    {
        return $this->hasMany(CostItem::class);
    }

    /**
     * @return HasMany<AllocationKey, $this>
     */
    public function allocationKeys(): HasMany
    {
        return $this->hasMany(AllocationKey::class);
    }

    /**
     * Systemweite Standardkategorien ohne Mandantenbindung.
     *
     * @param  Builder<static>  $query
     */
    public function scopeStandard(Builder $query): void
    {
        $query->whereNull('organization_id');
    }

    /**
     * Zum Stichtag gueltige Fassungen.
     *
     * @param  Builder<static>  $query
     */
    public function scopeValidOn(Builder $query, string $date): void
    {
        $query->where('valid_from', '<=', $date)
            ->where(function (Builder $inner) use ($date): void {
                $inner->whereNull('valid_to')->orWhere('valid_to', '>=', $date);
            });
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeApportionable(Builder $query): void
    {
        $query->where('apportionment_status', ApportionmentStatus::UMLAGEFAEHIG->value);
    }
}
