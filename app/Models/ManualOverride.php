<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ManualOverrideFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Revisionssichere Nutzerkorrektur an abrechnungsrelevanten Daten.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $billing_run_id
 * @property string|null $user_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $field
 * @property array<string, mixed>|null $old_value
 * @property array<string, mixed>|null $new_value
 * @property string|null $reason
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun|null $billingRun
 * @property-read Organization $organization
 * @property-read User|null $user
 */
class ManualOverride extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<ManualOverrideFactory> */
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
            'old_value' => 'array',
            'new_value' => 'array',
            'occurred_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
