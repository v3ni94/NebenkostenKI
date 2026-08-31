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
use Illuminate\Support\Carbon;

/**
 * Rechenzeile einer Mieterabrechnung.
 *
 * Enthaelt Gesamtkosten, Schluesseltext, Zaehler, Nenner, Zeitfaktor, Anteil in
 * Cent und den Rundungsausgleich. Die Summe aller share_cent einer Kostenart muss
 * exakt der verteilten Summe entsprechen.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $unit_statement_id
 * @property string|null $cost_category_id
 * @property string $category_label
 * @property string|null $betrkv_reference
 * @property int $total_cost_cent
 * @property AllocationKeyType $allocation_key_type
 * @property string $allocation_key_label
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $numerator
 * @property string|null $denominator
 * @property string $time_factor
 * @property int $share_cent
 * @property int $rounding_adjustment_cent
 * @property bool $is_heating_line
 * @property int|null $paragraph_35a_labor_cent
 * @property Paragraph35aType $paragraph_35a_type
 * @property int $sort_order
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CostCategory|null $costCategory
 * @property-read Organization $organization
 * @property-read UnitStatement $unitStatement
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
