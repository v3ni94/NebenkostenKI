<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenancy;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationAccess;

/**
 * Zugriff auf Mietverhaeltnisse einschliesslich Mieterpersonen und Zeitraeume.
 */
class TenancyPolicy
{
    use ResolvesOrganizationAccess;

    public function viewAny(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function view(User $user, Tenancy $tenancy): bool
    {
        return $this->isUsableAccount($user) && $this->isMember($user, $tenancy);
    }

    public function create(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function update(User $user, Tenancy $tenancy): bool
    {
        return $this->isUsableAccount($user) && $this->mayWrite($user, $tenancy);
    }

    public function delete(User $user, Tenancy $tenancy): bool
    {
        return $this->isUsableAccount($user) && $this->mayWrite($user, $tenancy);
    }

    public function restore(User $user, Tenancy $tenancy): bool
    {
        return $this->isOwner($user, $tenancy);
    }

    public function forceDelete(User $user, Tenancy $tenancy): bool
    {
        return false;
    }
}
