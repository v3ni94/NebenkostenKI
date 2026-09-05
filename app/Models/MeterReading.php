<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeterReadingKind;
use App\Enums\ValueSource;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\MeterReadingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ablesung. Verbrauchswert als DECIMAL(14,4).
 *
 * Fehlt bei einem Nutzerwechsel die Zwischenablesung, wird nicht geschaetzt. Eine
 * ausdruecklich bestaetigte Ersatzverteilung traegt is_estimated und confirmed_at.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $meter_device_id
 * @property string|null $tenancy_id
 * @property Carbon $read_on
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string $value
 * @property MeterReadingKind $reading_kind
 * @property ValueSource $source
 * @property bool $is_estimated
 * @property Carbon|null $confirmed_at
 * @property string|null $confirmed_by_user_id
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $confidence
 * @property string|null $document_id
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $confirmedBy
 * @property-read MeterDevice $meterDevice
 * @property-read Organization $organization
 * @property-read Tenancy|null $tenancy
 */
class MeterReading extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<MeterReadingFactory> */
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
            'read_on' => 'date',
            'value' => 'decimal:4',
            'reading_kind' => MeterReadingKind::class,
            'source' => ValueSource::class,
            'is_estimated' => 'boolean',
            'confirmed_at' => 'datetime',
            'confidence' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<MeterDevice, $this>
     */
    public function meterDevice(): BelongsTo
    {
        return $this->belongsTo(MeterDevice::class);
    }

    /**
     * @return BelongsTo<Tenancy, $this>
     */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
