<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ValueSource;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AllocationKeyValueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Einzelwert eines Verteilerschluessels als DECIMAL(20,6).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $allocation_key_id
 * @property string|null $unit_id
 * @property string|null $tenancy_id
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string $numerator
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property ValueSource $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AllocationKey $allocationKey
 * @property-read Organization $organization
 * @property-read Tenancy|null $tenancy
 * @property-read Unit|null $unit
 */
class AllocationKeyValue extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<AllocationKeyValueFactory> */
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
            'numerator' => 'decimal:6',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'source' => ValueSource::class,
        ];
    }

    /**
     * @return BelongsTo<AllocationKey, $this>
     */
    public function allocationKey(): BelongsTo
    {
        return $this->belongsTo(AllocationKey::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Tenancy, $this>
     */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }
}
