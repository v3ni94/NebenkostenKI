<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminRole;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Revisionsprotokoll.
 *
 * DATENSCHUTZ: Nur gekuerzte IP, gehashter User-Agent und technische Metadaten.
 * Supportzugriffe erfordern eine Begruendung in reason.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string|null $actor_user_id
 * @property AdminRole|null $actor_admin_role
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property Carbon $occurred_at
 * @property string|null $ip_truncated
 * @property string|null $user_agent_hash
 * @property array<string, mixed>|null $metadata
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $actor
 * @property-read Organization|null $organization
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    use HasUlids;

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
            'actor_admin_role' => AdminRole::class,
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
