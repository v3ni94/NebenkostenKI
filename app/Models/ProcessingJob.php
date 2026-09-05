<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcessingJobStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ProcessingJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Wiederanlaufbarer Teiljob der datenbankgestuetzten Queue.
 *
 * DATENSCHUTZ: In payload gehoeren ausschliesslich Referenz-IDs und technische
 * Parameter, niemals Dateiinhalte, OCR-Texte oder Prompts.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string|null $billing_run_id
 * @property string|null $document_id
 * @property string $job_type
 * @property ProcessingJobStatus $status
 * @property int $priority
 * @property int $attempts
 * @property int $max_attempts
 * @property string|null $lease_owner
 * @property Carbon|null $leased_until
 * @property Carbon|null $heartbeat_at
 * @property Carbon $available_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property array<string, mixed>|null $payload
 * @property string|null $error_code
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun|null $billingRun
 * @property-read Document|null $document
 * @property-read Organization|null $organization
 */
class ProcessingJob extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<ProcessingJobFactory> */
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
            'status' => ProcessingJobStatus::class,
            'priority' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'leased_until' => 'datetime',
            'heartbeat_at' => 'datetime',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'payload' => 'array',
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
     * Faellige Jobs ohne gueltiges Lease.
     *
     * @param  Builder<static>  $query
     */
    public function scopeClaimable(Builder $query): void
    {
        $query->where('status', ProcessingJobStatus::BEREIT->value)
            ->where('available_at', '<=', now())
            ->where(function (Builder $inner): void {
                $inner->whereNull('leased_until')->orWhere('leased_until', '<=', now());
            });
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeDeadLetter(Builder $query): void
    {
        $query->where('status', ProcessingJobStatus::DEAD_LETTER->value);
    }
}
