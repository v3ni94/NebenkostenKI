<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\HeatingStatementLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Einzelbetrag einer Heizkostenabrechnung je Einheit.
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
