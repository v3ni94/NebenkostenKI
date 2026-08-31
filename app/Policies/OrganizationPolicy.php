<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationAccess;

/**
 * Zugriff auf den Mandanten selbst.
 */
class OrganizationPolicy
{
    use ResolvesOrganizationAccess;

    public function viewAny(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->isUsableAccount($user) && $user->belongsToOrganization($organization);
    }

    public function create(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->hasRole($user, $organization, OrganizationRole::OWNER);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->hasRole($user, $organization, OrganizationRole::OWNER);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->hasRole($user, $organization, OrganizationRole::OWNER);
    }

    public function manageBilling(User $user, Organization $organization): bool
    {
        if (! $user->belongsToOrganization($organization)) {
            return false;
        }

        return $user->roleIn($organization)?->mayManageBilling() === true;
    }

    private function hasRole(User $user, Organization $organization, OrganizationRole $role): bool
    {
        return $this->isUsableAccount($user)
            && $user->belongsToOrganization($organization)
            && $user->roleIn($organization) === $role;
    }
}
