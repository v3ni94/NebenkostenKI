<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingMode;
use App\Enums\BillingRunStatus;
use App\Enums\HeatingSupplyCase;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\BillingRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Abrechnungslauf. Zentrale Aggregatwurzel eines Abrechnungsjahres.
 *
 * active_calculation_snapshot_id ist eine ULID-Spalte ohne Fremdschluessel, um
 * eine zirkulaere Abhaengigkeit in den Migrationen zu vermeiden. Die Konsistenz
 * sichert die Anwendungsschicht innerhalb der Transaktion.
 */
class BillingRun extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    /** @use HasFactory<BillingRunFactory> */
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
            'billing_year' => 'integer',
            'mode' => BillingMode::class,
            'status' => BillingRunStatus::class,
            'wizard_step' => 'integer',
            'heating_supply_case' => HeatingSupplyCase::class,
            'statement_count' => 'integer',
            'price_per_statement_gross_cent' => 'integer',
            'price_base_gross_cent' => 'integer',
            'price_total_gross_cent' => 'integer',
            'vat_rate_percent' => 'decimal:4',
            'price_quoted_at' => 'datetime',
            'price_locked_at' => 'datetime',
            'uploaded_bytes' => 'integer',
            'review_confirmed_at' => 'datetime',
            'responsibility_confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<Landlord, $this>
     */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<BillingRun, $this>
     */
    public function previousBillingRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_billing_run_id');
    }

    /**
     * @return HasMany<BillingRunVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(BillingRunVersion::class);
    }

    /**
     * @return HasMany<CalculationSnapshot, $this>
     */
    public function calculationSnapshots(): HasMany
    {
        return $this->hasMany(CalculationSnapshot::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<CostItem, $this>
     */
    public function costItems(): HasMany
    {
        return $this->hasMany(CostItem::class);
    }

    /**
     * @return HasMany<AllocationKey, $this>
     */
    public function allocationKeys(): HasMany
    {
        return $this->hasMany(AllocationKey::class);
    }

    /**
     * @return HasMany<Prepayment, $this>
     */
    public function prepayments(): HasMany
    {
        return $this->hasMany(Prepayment::class);
    }

    /**
     * @return HasMany<HeatingStatement, $this>
     */
    public function heatingStatements(): HasMany
    {
        return $this->hasMany(HeatingStatement::class);
    }

    /**
     * @return HasMany<UnitStatement, $this>
     */
    public function unitStatements(): HasMany
    {
        return $this->hasMany(UnitStatement::class);
    }

    /**
     * @return HasMany<ValidationIssue, $this>
     */
    public function validationIssues(): HasMany
    {
        return $this->hasMany(ValidationIssue::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<GeneratedDocument, $this>
     */
    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    /**
     * @return BelongsTo<CalculationSnapshot, $this>
     */
    public function activeCalculationSnapshot(): BelongsTo
    {
        return $this->belongsTo(CalculationSnapshot::class, 'active_calculation_snapshot_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWithStatus(Builder $query, BillingRunStatus ...$status): void
    {
        $query->whereIn('status', array_map(
            static fn (BillingRunStatus $case): string => $case->value,
            $status
        ));
    }

    /**
     * Laeufe, die eine Erinnerung ausloesen koennen.
     *
     * @param  Builder<static>  $query
     */
    public function scopeNotFinalized(Builder $query): void
    {
        $query->whereNotIn('status', [
            BillingRunStatus::FINALIZED->value,
            BillingRunStatus::CANCELLED->value,
        ]);
    }
}
