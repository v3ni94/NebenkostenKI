<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationKeyType;
use App\Enums\PropertyKind;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Objekt beziehungsweise Liegenschaft.
 *
 * Flaechen als DECIMAL(10,4), Miteigentumsanteile als DECIMAL(12,6). Die
 * Bezeichnungen der individuellen Schluessel 1 bis 5 liegen am Objekt, die
 * zugehoerigen Werte je Einheit.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $created_by_user_id
 * @property string|null $landlord_id
 * @property string $label
 * @property string $address_line
 * @property string|null $address_extra
 * @property string $postal_code
 * @property string $city
 * @property string $country
 * @property PropertyKind $kind
 * @property string|null $weg_name
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $total_living_area_sqm
 * @property string|null $total_heated_area_sqm
 * @property string|null $mea_denominator
 * @property string|null $individual_key_1_label
 * @property string|null $individual_key_2_label
 * @property string|null $individual_key_3_label
 * @property string|null $individual_key_4_label
 * @property string|null $individual_key_5_label
 * @property bool $is_active
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, BillingRun> $billingRuns
 * @property-read User|null $createdBy
 * @property-read Landlord|null $landlord
 * @property-read Collection<int, MeterDevice> $meterDevices
 * @property-read Organization $organization
 * @property-read Collection<int, ReminderPreference> $reminderPreferences
 * @property-read Collection<int, Tenancy> $tenancies
 * @property-read Collection<int, Unit> $units
 */
class Property extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    /** @use HasFactory<PropertyFactory> */
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
            'kind' => PropertyKind::class,
            'total_living_area_sqm' => 'decimal:4',
            'total_heated_area_sqm' => 'decimal:4',
            'mea_denominator' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Landlord, $this>
     */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * @return HasMany<Tenancy, $this>
     */
    public function tenancies(): HasMany
    {
        return $this->hasMany(Tenancy::class);
    }

    /**
     * @return HasMany<MeterDevice, $this>
     */
    public function meterDevices(): HasMany
    {
        return $this->hasMany(MeterDevice::class);
    }

    /**
     * @return HasMany<BillingRun, $this>
     */
    public function billingRuns(): HasMany
    {
        return $this->hasMany(BillingRun::class);
    }

    /**
     * @return HasMany<ReminderPreference, $this>
     */
    public function reminderPreferences(): HasMany
    {
        return $this->hasMany(ReminderPreference::class);
    }

    /**
     * Bezeichnung eines individuellen Schluessels, falls am Objekt gepflegt.
     */
    public function individualKeyLabel(AllocationKeyType $type): ?string
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

        $value = $this->getAttribute('individual_key_'.$index.'_label');

        return is_string($value) ? $value : null;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
