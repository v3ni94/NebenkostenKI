<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationAccess;

class UnitPolicy
{
    use ResolvesOrganizationAccess;

    public function viewAny(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function view(User $user, Unit $unit): bool
    {
        return $this->isUsableAccount($user) && $this->isMember($user, $unit);
    }

    public function create(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function update(User $user, Unit $unit): bool
    {
        return $this->isUsableAccount($user) && $this->mayWrite($user, $unit);
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $this->isUsableAccount($user) && $this->mayWrite($user, $unit);
    }

    public function restore(User $user, Unit $unit): bool
    {
        return $this->isOwner($user, $unit);
    }

    public function forceDelete(User $user, Unit $unit): bool
    {
        return false;
    }
}
