<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminRole;
use Database\Factories\AdminRoleAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Interne Rollenzuweisung des Betreibers, Tabelle admin_roles.
 *
 * Vollstaendig getrennt von den Kundenrollen. Supportzugriffe erfordern Rolle,
 * Begruendung und einen Audit-Eintrag.
 */
class AdminRoleAssignment extends Model
{
    /** @use HasFactory<AdminRoleAssignmentFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'admin_roles';

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
            'role' => AdminRole::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
