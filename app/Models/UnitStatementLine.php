<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationKeyType;
use App\Enums\Paragraph35aType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\UnitStatementLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rechenzeile einer Mieterabrechnung.
 *
 * Enthaelt Gesamtkosten, Schluesseltext, Zaehler, Nenner, Zeitfaktor, Anteil in
 * Cent und den Rundungsausgleich. Die Summe aller share_cent einer Kostenart muss
 * exakt der verteilten Summe entsprechen.
 */
class UnitStatementLine extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<UnitStatementLineFactory> */
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
            'total_cost_cent' => 'integer',
            'allocation_key_type' => AllocationKeyType::class,
            'numerator' => 'decimal:6',
            'denominator' => 'decimal:6',
            'time_factor' => 'decimal:8',
            'share_cent' => 'integer',
            'rounding_adjustment_cent' => 'integer',
            'is_heating_line' => 'boolean',
            'paragraph_35a_labor_cent' => 'integer',
            'paragraph_35a_type' => Paragraph35aType::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<UnitStatement, $this>
     */
    public function unitStatement(): BelongsTo
    {
        return $this->belongsTo(UnitStatement::class);
    }

    /**
     * @return BelongsTo<CostCategory, $this>
     */
    public function costCategory(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class);
    }
}
