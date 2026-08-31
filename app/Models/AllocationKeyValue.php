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

/**
 * Einzelwert eines Verteilerschluessels als DECIMAL(20,6).
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
