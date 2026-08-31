<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationKeyType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Einheit eines Objekts.
 *
 * Flaechen DECIMAL(10,4), MEA DECIMAL(12,6), individuelle Schluesselwerte
 * DECIMAL(14,4). Alle Werte werden als String gelesen, damit keine binaere
 * Gleitkommaungenauigkeit entsteht.
 */
class Unit extends Model
{
    /** @use HasFactory<\Database\Factories\UnitFactory> */
    use HasFactory;

    use BelongsToOrganization, HasUlids, SoftDeletes;

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
            'living_area_sqm' => 'decimal:4',
            'heated_area_sqm' => 'decimal:4',
            'mea' => 'decimal:6',
            'room_count' => 'integer',
            'individual_key_1_value' => 'decimal:4',
            'individual_key_2_value' => 'decimal:4',
            'individual_key_3_value' => 'decimal:4',
            'individual_key_4_value' => 'decimal:4',
            'individual_key_5_value' => 'decimal:4',
            'is_commercial' => 'boolean',
            'is_owner_occupied' => 'boolean',
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
     * @return HasMany<Tenancy, $this>
     */
    public function tenancies(): HasMany
    {
        return $this->hasMany(Tenancy::class);
    }

    /**
     * @return HasMany<VacancyPeriod, $this>
     */
    public function vacancyPeriods(): HasMany
    {
        return $this->hasMany(VacancyPeriod::class);
    }

    /**
     * @return HasMany<MeterDevice, $this>
     */
    public function meterDevices(): HasMany
    {
        return $this->hasMany(MeterDevice::class);
    }

    /**
     * @return HasMany<UnitStatement, $this>
     */
    public function unitStatements(): HasMany
    {
        return $this->hasMany(UnitStatement::class);
    }

    /**
     * Zaehlerwert eines individuellen Schluessels als String, oder null.
     */
    public function individualKeyValue(AllocationKeyType $type): ?string
    {
        $index = match ($type) {
            AllocationKeyType::INDIVIDUELL_1 => 1,
            AllocationKeyType::INDIVIDUELL_2 => 2,
            AllocationKeyType::INDIVIDUELL_3 => 3,
            AllocationKeyType::INDIVIDUELL_4 => 4,
            AllocationKeyType::INDIVIDUELL_5 => 5,
            default => null,
        };

        if ($index === null) {
            return null;
        }

        $value = $this->getAttribute('individual_key_'.$index.'_value');

        return $value === null ? null : (string) $value;
    }
}
