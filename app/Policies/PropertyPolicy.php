<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Property;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationAccess;

class PropertyPolicy
{
    use ResolvesOrganizationAccess;

    public function viewAny(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function view(User $user, Property $property): bool
    {
        return $this->isUsableAccount($user) && $this->isMember($user, $property);
    }

    public function create(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function update(User $user, Property $property): bool
    {
        return $this->isUsableAccount($user) && $this->mayWrite($user, $property);
    }

    public function delete(User $user, Property $property): bool
    {
        return $this->isUsableAccount($user) && $this->mayWrite($user, $property);
    }

    public function restore(User $user, Property $property): bool
    {
        return $this->isOwner($user, $property);
    }

    public function forceDelete(User $user, Property $property): bool
    {
        return false;
    }
}
