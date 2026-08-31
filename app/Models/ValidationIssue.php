<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ValidationIssueStatus;
use App\Enums\ValidationSeverity;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ValidationIssueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ergebnis einer Pruefregel.
 *
 * Blocker verhindern die Finalisierung. Warnungen erfordern eine ausdrueckliche
 * Nutzerentscheidung. Jede Regel besitzt Code, Version, Severity, Beschreibung und
 * soweit gesichert eine fachliche oder rechtliche Referenz.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $billing_run_id
 * @property string $rule_code
 * @property string $rule_version
 * @property ValidationSeverity $severity
 * @property ValidationIssueStatus $status
 * @property bool $blocks_finalization
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property string $title
 * @property string $description
 * @property string|null $legal_reference
 * @property string|null $resolution
 * @property Carbon $detected_at
 * @property string|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun $billingRun
 * @property-read Organization $organization
 * @property-read User|null $resolvedBy
 */
class ValidationIssue extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<ValidationIssueFactory> */
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
            'severity' => ValidationSeverity::class,
            'status' => ValidationIssueStatus::class,
            'blocks_finalization' => 'boolean',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * Offene Blocker. Solange eine Zeile existiert, ist keine Finalisierung moeglich.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOpenBlockers(Builder $query): void
    {
        $query->where('status', ValidationIssueStatus::OFFEN->value)
            ->where('blocks_finalization', true);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ValidationIssueStatus::OFFEN->value);
    }
}
