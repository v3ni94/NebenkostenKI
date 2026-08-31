<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatementResultKind;
use App\Enums\UnitStatementStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\UnitStatementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mieterabrechnung. Preisgrundlage ist die erzeugte Abrechnung, nicht die Wohnung.
 *
 * balance_cent ist positiv bei Nachzahlung und negativ bei Guthaben. Eine
 * finalisierte Abrechnung wird nie ueberschrieben, Korrekturen erzeugen eine neue
 * Version und die alte erhaelt den Status ERSETZT.
 */
class UnitStatement extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<UnitStatementFactory> */
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
            'sequence_number' => 'integer',
            'version_number' => 'integer',
            'usage_period_start' => 'date',
            'usage_period_end' => 'date',
            'days_used' => 'integer',
            'period_days' => 'integer',
            'total_apportionable_cent' => 'integer',
            'total_heating_cent' => 'integer',
            'total_excluded_cent' => 'integer',
            'prepayment_target_cent' => 'integer',
            'prepayment_actual_cent' => 'integer',
            'balance_cent' => 'integer',
            'rounding_adjustment_total_cent' => 'integer',
            'paragraph_35a_household_cent' => 'integer',
            'paragraph_35a_craftsman_cent' => 'integer',
            'result_kind' => StatementResultKind::class,
            'status' => UnitStatementStatus::class,
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
     * @return BelongsTo<Tenancy, $this>
     */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<CalculationSnapshot, $this>
     */
    public function calculationSnapshot(): BelongsTo
    {
        return $this->belongsTo(CalculationSnapshot::class);
    }

    /**
     * @return BelongsTo<UnitStatement, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_statement_id');
    }

    /**
     * @return HasMany<UnitStatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(UnitStatementLine::class);
    }

    /**
     * @return HasMany<GeneratedDocument, $this>
     */
    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('status', '!=', UnitStatementStatus::ERSETZT->value);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeFinal(Builder $query): void
    {
        $query->where('status', UnitStatementStatus::FINAL->value);
    }
}
