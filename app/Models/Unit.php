<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationKeyType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Einheit eines Objekts.
 *
 * Flaechen DECIMAL(10,4), MEA DECIMAL(12,6), individuelle Schluesselwerte
 * DECIMAL(14,4). Alle Werte werden als String gelesen, damit keine binaere
 * Gleitkommaungenauigkeit entsteht.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string $label
 * @property string|null $location
 * @property string|null $unit_number
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $living_area_sqm
 * @property string|null $heated_area_sqm
 * @property string|null $mea
 * @property int|null $room_count
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $individual_key_1_value
 * @property string|null $individual_key_2_value
 * @property string|null $individual_key_3_value
 * @property string|null $individual_key_4_value
 * @property string|null $individual_key_5_value
 * @property bool $is_commercial
 * @property bool $is_owner_occupied
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, MeterDevice> $meterDevices
 * @property-read Organization $organization
 * @property-read Property $property
 * @property-read Collection<int, Tenancy> $tenancies
 * @property-read Collection<int, UnitStatement> $unitStatements
 * @property-read Collection<int, VacancyPeriod> $vacancyPeriods
 */
class Unit extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Loeschkaskade: Mit einer weich geloeschten Einheit werden ihre
     * Mietverhaeltnisse weich geloescht. Sie sind ohne Einheit fachlich nicht
     * mehr zuzuordnen und duerfen nicht als verwaiste Zeilen in einer spaeteren
     * Abrechnung auftauchen. Die endgueltige Loeschung laeuft ueber die
     * Fremdschluessel der Datenbank.
     */
    protected static function booted(): void
    {
        static::deleting(static function (Unit $unit): void {
            if ($unit->isForceDeleting()) {
                return;
            }

            foreach ($unit->tenancies()->get() as $tenancy) {
                $tenancy->delete();
            }
        });
    }

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
