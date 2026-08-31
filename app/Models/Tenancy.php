<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenancyKind;
use App\Enums\TenancyStatus;
use App\Enums\ValueSource;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\TenancyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mietverhaeltnis. Preisgrundlage ist die erzeugte Mieterabrechnung, daher
 * koennen bei Mieterwechsel je Einheit mehrere Mietverhaeltnisse bestehen.
 *
 * Ueberschneidungen und Luecken prueft die Regel-Engine. Bei ausgezogenem Mieter
 * ist die Zustellanschrift zwingend erforderlich.
 */
class Tenancy extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    /** @use HasFactory<TenancyFactory> */
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
            'kind' => TenancyKind::class,
            'status' => TenancyStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'monthly_operating_prepayment_cent' => 'integer',
            'monthly_heating_prepayment_cent' => 'integer',
            'heating_prepayment_separate' => 'boolean',
            'operating_costs_apportionment_agreed' => 'boolean',
            'other_operating_costs_agreed' => 'boolean',
            'contract_data_source' => ValueSource::class,
        ];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<TenancyPerson, $this>
     */
    public function persons(): HasMany
    {
        return $this->hasMany(TenancyPerson::class);
    }

    /**
     * @return HasMany<OccupancyPeriod, $this>
     */
    public function occupancyPeriods(): HasMany
    {
        return $this->hasMany(OccupancyPeriod::class);
    }

    /**
     * @return HasMany<Prepayment, $this>
     */
    public function prepayments(): HasMany
    {
        return $this->hasMany(Prepayment::class);
    }

    /**
     * @return HasMany<UnitStatement, $this>
     */
    public function unitStatements(): HasMany
    {
        return $this->hasMany(UnitStatement::class);
    }

    /**
     * @return HasMany<MeterReading, $this>
     */
    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    public function hasMovedOut(): bool
    {
        return $this->getAttribute('ends_on') !== null;
    }

    /**
     * Mietverhaeltnisse, die einen Zeitraum beruehren.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOverlappingPeriod(Builder $query, string $from, string $to): void
    {
        $query->where('starts_on', '<=', $to)
            ->where(function (Builder $inner) use ($from): void {
                $inner->whereNull('ends_on')->orWhere('ends_on', '>=', $from);
            });
    }
}
