<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeterType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\MeterDeviceFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Messeinrichtung. Ohne unit_id handelt es sich um einen Allgemeinzaehler.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string|null $unit_id
 * @property MeterType $meter_type
 * @property string $meter_number
 * @property string|null $measurement_unit
 * @property string|null $location
 * @property Carbon|null $installed_on
 * @property Carbon|null $removed_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Organization $organization
 * @property-read Property $property
 * @property-read Collection<int, MeterReading> $readings
 * @property-read Unit|null $unit
 */
class MeterDevice extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    /** @use HasFactory<MeterDeviceFactory> */
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
            'meter_type' => MeterType::class,
            'installed_on' => 'date',
            'removed_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return HasMany<MeterReading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }
}
