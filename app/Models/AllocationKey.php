<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AllocationKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Verteilerschluessel einer Kostenart oder einer einzelnen Position.
 *
 * Vorschlagsreihenfolge: bestaetigte Mietvertragsregelung, bestaetigter Schluessel
 * aus dem Vorjahr, fachlich naheliegender Default mit Warnhinweis. WEG-Schluessel
 * und mietvertraglicher Umlageschluessel werden nicht gleichgesetzt.
 *
 * Nenner null oder negativ ist ein Blocker der Regel-Engine.
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
