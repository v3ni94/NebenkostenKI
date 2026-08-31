<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeterReadingKind;
use App\Enums\ValueSource;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ablesung. Verbrauchswert als DECIMAL(14,4).
 *
 * Fehlt bei einem Nutzerwechsel die Zwischenablesung, wird nicht geschaetzt. Eine
 * ausdruecklich bestaetigte Ersatzverteilung traegt is_estimated und confirmed_at.
 */
class MeterReading extends Model
{
    /** @use HasFactory<\Database\Factories\MeterReadingFactory> */
    use HasFactory;

    use BelongsToOrganization, HasUlids;

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
