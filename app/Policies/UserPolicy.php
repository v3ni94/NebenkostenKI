<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AdminRole;
use App\Enums\OrganizationRole;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationAccess;

/**
 * Zugriff auf Nutzerdatensaetze.
 *
 * Ein Nutzer sieht und aendert grundsaetzlich nur sich selbst. Ein Inhaber darf
 * die Mitglieder seiner eigenen Organisation sehen. Interne Rollen sind davon
 * getrennt und werden ausschliesslich ueber admin_roles geprueft.
 */
class UserPolicy
{
    use ResolvesOrganizationAccess;

    public function viewAny(User $user): bool
    {
        return $user->hasAdminRole(AdminRole::ADMIN) || $user->hasAdminRole(AdminRole::SUPPORT);
    }

    public function view(User $user, User $model): bool
    {
        if (! $this->isUsableAccount($user)) {
            return false;
        }

        if ($user->is($model)) {
            return true;
        }

        if ($user->hasAdminRole(AdminRole::ADMIN) || $user->hasAdminRole(AdminRole::SUPPORT)) {
            return true;
        }

        return $this->sharesOwnedOrganization($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasAdminRole(AdminRole::ADMIN);
    }

    public function update(User $user, User $model): bool
    {
        return $this->isUsableAccount($user) && $user->is($model);
    }

    public function delete(User $user, User $model): bool
    {
        return $this->isUsableAccount($user) && $user->is($model);
    }

    public function restore(User $user, User $model): bool
    {
        return $user->hasAdminRole(AdminRole::ADMIN);
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Nutzer, in dessen Organisation der Akteur Inhaber ist.
     */
    private function sharesOwnedOrganization(User $user, User $model): bool
    {
        $shared = array_intersect($user->organizationIds(), $model->organizationIds());

        foreach ($shared as $organizationId) {
            if ($user->roleIn($organizationId) === OrganizationRole::OWNER) {
                return true;
            }
        }

        return false;
    }
}
