<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\BillingRunVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Unveraenderliche Version der abrechnungsrelevanten Nutzereingaben.
 *
 * Datensaetze werden ausschliesslich angelegt, niemals geaendert. Daher fuehrt die
 * Tabelle nur created_at.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $billing_run_id
 * @property int $version_number
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property string|null $reason
 * @property string|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property-read BillingRun $billingRun
 * @property-read User|null $createdBy
 * @property-read Organization $organization
 */
class BillingRunVersion extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<BillingRunVersionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

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
            'payload' => 'array',
            'created_at' => 'datetime',
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
