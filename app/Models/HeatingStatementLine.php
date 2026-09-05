<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\HeatingStatementLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Einzelbetrag einer Heizkostenabrechnung je Einheit.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $heating_statement_id
 * @property string|null $unit_id
 * @property string|null $tenancy_id
 * @property string|null $unit_label
 * @property int|null $share_total_cent
 * @property int|null $share_basic_cent
 * @property int|null $share_consumption_cent
 * @property int|null $share_heating_cent
 * @property int|null $share_warm_water_cent
 * @property int|null $share_co2_cent
 * @property int|null $share_co2_landlord_cent
 * @property int|null $share_co2_tenant_cent
 * @property int|null $share_other_cent
 * @property int|null $usage_days
 * @property string|null $usage_period_label
 * @property bool $manual_heating_entry
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $consumption
 * @property string|null $consumption_unit
 * @property Carbon|null $usage_period_start
 * @property Carbon|null $usage_period_end
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $confidence
 * @property int|null $source_page
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read HeatingStatement $heatingStatement
 * @property-read Organization $organization
 * @property-read Tenancy|null $tenancy
 * @property-read Unit|null $unit
 */
class HeatingStatementLine extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<HeatingStatementLineFactory> */
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
            'share_total_cent' => 'integer',
            'share_basic_cent' => 'integer',
            'share_consumption_cent' => 'integer',
            'share_heating_cent' => 'integer',
            'share_warm_water_cent' => 'integer',
            'share_co2_cent' => 'integer',
            // Manuelle Erfassung fuer Heizkostenfall B, siehe ADR-014.
            'share_co2_landlord_cent' => 'integer',
            'share_co2_tenant_cent' => 'integer',
            'share_other_cent' => 'integer',
            'usage_days' => 'integer',
            'manual_heating_entry' => 'boolean',
            'consumption' => 'decimal:4',
            'usage_period_start' => 'date',
            'usage_period_end' => 'date',
            'confidence' => 'decimal:4',
            'source_page' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<HeatingStatement, $this>
     */
    public function heatingStatement(): BelongsTo
    {
        return $this->belongsTo(HeatingStatement::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Tenancy, $this>
     */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }
}
