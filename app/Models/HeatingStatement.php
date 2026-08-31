<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Co2ShareStatus;
use App\Enums\HeatingSupplyCase;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\HeatingStatementFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Externe oder eigene Heizkostenabrechnung samt Pruefsummen.
 *
 * Liegt eine externe Abrechnung vor, duerfen deren Einzelbetraege nicht
 * zusaetzlich aus einer WEG-Summenposition angesetzt werden. Abweichungen
 * oberhalb der konfigurierten Toleranz blockieren die Finalisierung.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $billing_run_id
 * @property string|null $document_id
 * @property string|null $provider_name
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property HeatingSupplyCase $supply_case
 * @property int|null $total_cost_cent
 * @property int|null $basic_cost_cent
 * @property int|null $consumption_cost_cent
 * @property int|null $heating_cost_cent
 * @property int|null $warm_water_cost_cent
 * @property int|null $operating_current_cent
 * @property int|null $co2_cost_cent
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $basic_cost_share_percent
 * @property Co2ShareStatus $co2_share_status
 * @property int|null $checksum_lines_total_cent
 * @property int|null $checksum_difference_cent
 * @property bool|null $checksum_ok
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun $billingRun
 * @property-read Document|null $document
 * @property-read Collection<int, HeatingStatementLine> $lines
 * @property-read Organization $organization
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
