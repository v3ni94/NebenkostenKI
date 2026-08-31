<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AllocationKeyFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Verteilerschluessel einer Kostenart oder einer einzelnen Position.
 *
 * Vorschlagsreihenfolge: bestaetigte Mietvertragsregelung, bestaetigter Schluessel
 * aus dem Vorjahr, fachlich naheliegender Default mit Warnhinweis. WEG-Schluessel
 * und mietvertraglicher Umlageschluessel werden nicht gleichgesetzt.
 *
 * Nenner null oder negativ ist ein Blocker der Regel-Engine.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $billing_run_id
 * @property string|null $cost_category_id
 * @property string|null $cost_item_id
 * @property AllocationKeyType $key_type
 * @property AllocationKeySource $source
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $denominator
 * @property string|null $measurement_unit
 * @property string|null $label
 * @property string|null $confirmed_by_user_id
 * @property Carbon|null $confirmed_at
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun $billingRun
 * @property-read User|null $confirmedBy
 * @property-read CostCategory|null $costCategory
 * @property-read CostItem|null $costItem
 * @property-read Organization $organization
 * @property-read Collection<int, AllocationKeyValue> $values
 */
class AllocationKey extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<AllocationKeyFactory> */
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
            'key_type' => AllocationKeyType::class,
            'source' => AllocationKeySource::class,
            'denominator' => 'decimal:6',
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
     * @return BelongsTo<CostItem, $this>
     */
    public function costItem(): BelongsTo
    {
        return $this->belongsTo(CostItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /**
     * @return HasMany<AllocationKeyValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AllocationKeyValue::class);
    }

    public function isConfirmed(): bool
    {
        return $this->getAttribute('confirmed_at') !== null;
    }
}
