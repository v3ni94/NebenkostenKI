<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminRole;
use App\Enums\OrganizationRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Kundennutzer. Adminrollen liegen getrennt in admin_roles.
 *
 * Jeder Nutzer gehoert mindestens einer Organisation an. Die Organisation ist
 * der Mandant, ueber den alle Kundendaten gescopet werden.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUlids, Notifiable, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot(['role', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<OrganizationUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    /**
     * @return HasMany<AdminRoleAssignment, $this>
     */
    public function adminRoles(): HasMany
    {
        return $this->hasMany(AdminRoleAssignment::class);
    }

    /**
     * @return HasMany<BillingRun, $this>
     */
    public function billingRuns(): HasMany
    {
        return $this->hasMany(BillingRun::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<ReminderPreference, $this>
     */
    public function reminderPreferences(): HasMany
    {
        return $this->hasMany(ReminderPreference::class);
    }

    /**
     * IDs aller Organisationen des Nutzers. Grundlage jedes Mandantenscopes.
     *
     * @return list<string>
     */
    public function organizationIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->memberships()->pluck('organization_id')->all();

        return $ids;
    }

    /**
     * Rolle des Nutzers in einer konkreten Organisation.
     */
    public function roleIn(Organization|string $organization): ?OrganizationRole
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        $membership = $this->memberships->firstWhere('organization_id', $id)
            ?? $this->memberships()->where('organization_id', $id)->first();

        $role = $membership?->getAttribute('role');

        return $role instanceof OrganizationRole ? $role : null;
    }

    public function belongsToOrganization(Organization|string $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return in_array($id, $this->organizationIds(), true);
    }

    /**
     * Aktive interne Rolle. Adminrechte werden niemals aus Kundenrollen abgeleitet.
     */
    public function hasAdminRole(AdminRole $role): bool
    {
        return $this->adminRoles()
            ->where('role', $role->value)
            ->whereNull('revoked_at')
            ->exists();
    }

    public function isStaff(): bool
    {
        return $this->adminRoles()->whereNull('revoked_at')->exists();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', UserStatus::AKTIV->value);
    }
}
