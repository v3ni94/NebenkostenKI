<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Co2ShareStatus;
use App\Enums\HeatingSupplyCase;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\HeatingStatementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Externe oder eigene Heizkostenabrechnung samt Pruefsummen.
 *
 * Liegt eine externe Abrechnung vor, duerfen deren Einzelbetraege nicht
 * zusaetzlich aus einer WEG-Summenposition angesetzt werden. Abweichungen
 * oberhalb der konfigurierten Toleranz blockieren die Finalisierung.
 */
class HeatingStatement extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<HeatingStatementFactory> */
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
            'period_start' => 'date',
            'period_end' => 'date',
            'supply_case' => HeatingSupplyCase::class,
            'total_cost_cent' => 'integer',
            'basic_cost_cent' => 'integer',
            'consumption_cost_cent' => 'integer',
            'heating_cost_cent' => 'integer',
            'warm_water_cost_cent' => 'integer',
            'operating_current_cent' => 'integer',
            'co2_cost_cent' => 'integer',
            'basic_cost_share_percent' => 'decimal:4',
            'co2_share_status' => Co2ShareStatus::class,
            'checksum_lines_total_cent' => 'integer',
            'checksum_difference_cent' => 'integer',
            'checksum_ok' => 'boolean',
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
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return HasMany<HeatingStatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(HeatingStatementLine::class);
    }
}
