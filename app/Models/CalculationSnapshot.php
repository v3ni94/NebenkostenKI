<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CalculationSnapshotStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CalculationSnapshotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Unveraenderlicher Berechnungsstand.
 *
 * Ein bezahlter Snapshot wird gesperrt und niemals ueberschrieben. Final-PDFs
 * werden vollstaendig aus diesem Stand neu erzeugt.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $billing_run_id
 * @property string|null $replaced_by_snapshot_id
 * @property int $version_number
 * @property array<string, mixed> $input
 * @property array<string, mixed> $result
 * @property string $domain_version
 * @property string $ruleset_version
 * @property string $hash
 * @property CalculationSnapshotStatus $status
 * @property int $statement_count
 * @property int $total_apportionable_cent
 * @property int $total_prepayment_actual_cent
 * @property int $total_balance_cent
 * @property Carbon|null $locked_at
 * @property string|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun $billingRun
 * @property-read Collection<int, GeneratedDocument> $generatedDocuments
 * @property-read Organization $organization
 * @property-read CalculationSnapshot|null $replacedBy
 * @property-read Collection<int, UnitStatement> $unitStatements
 */
class CalculationSnapshot extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<CalculationSnapshotFactory> */
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
            'version_number' => 'integer',
            'input' => 'array',
            'result' => 'array',
            'status' => CalculationSnapshotStatus::class,
            'statement_count' => 'integer',
            'total_apportionable_cent' => 'integer',
            'total_prepayment_actual_cent' => 'integer',
            'total_balance_cent' => 'integer',
            'locked_at' => 'datetime',
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
     * @return BelongsTo<CalculationSnapshot, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_snapshot_id');
    }

    /**
     * @return HasMany<UnitStatement, $this>
     */
    public function unitStatements(): HasMany
    {
        return $this->hasMany(UnitStatement::class);
    }

    /**
     * @return HasMany<GeneratedDocument, $this>
     */
    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    public function isLocked(): bool
    {
        return $this->getAttribute('locked_at') !== null;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeLocked(Builder $query): void
    {
        $query->whereNotNull('locked_at');
    }
}
