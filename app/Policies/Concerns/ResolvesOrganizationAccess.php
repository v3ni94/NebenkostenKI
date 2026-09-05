<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Gemeinsame Mandantenpruefung aller Policies.
 *
 * VERBINDLICH: Die Pruefung erfolgt immer objektbezogen anhand der Spalte
 * organization_id der geladenen Entitaet. Eine Freigabe allein aufgrund einer
 * URL-ID ist unzulaessig. Das Query-Scoping in den Modellen ersetzt die Policy
 * nicht, beide Ebenen greifen zusammen.
 *
 * Die Policies werden ueber die Laravel-Namenskonvention automatisch gefunden
 * (App\Models\X zu App\Policies\XPolicy). Eine manuelle Registrierung in einem
 * ServiceProvider ist nicht erforderlich und wird bewusst nicht vorgenommen,
 * weil App\Providers\AppServiceProvider einem anderen Arbeitspaket gehoert.
 */
trait ResolvesOrganizationAccess
{
    protected function organizationIdOf(Model $model): ?string
    {
        $value = $model->getAttribute('organization_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Objektbezogene Mandantenpruefung.
     */
    protected function isMember(User $user, Model $model): bool
    {
        $organizationId = $this->organizationIdOf($model);

        if ($organizationId === null) {
            return false;
        }

        return in_array($organizationId, $user->organizationIds(), true);
    }

    protected function roleOf(User $user, Model $model): ?OrganizationRole
    {
        $organizationId = $this->organizationIdOf($model);

        if ($organizationId === null) {
            return null;
        }

        return $user->roleIn($organizationId);
    }

    /**
     * Schreibrecht: Mitgliedschaft und eine Rolle, die aendern darf.
     */
    protected function mayWrite(User $user, Model $model): bool
    {
        if (! $this->isMember($user, $model)) {
            return false;
        }

        return $this->roleOf($user, $model)?->mayWrite() === true;
    }

    /**
     * Zahlungs- und Rechnungsrecht.
     */
    protected function mayManageBilling(User $user, Model $model): bool
    {
        if (! $this->isMember($user, $model)) {
            return false;
        }

        return $this->roleOf($user, $model)?->mayManageBilling() === true;
    }

    protected function isOwner(User $user, Model $model): bool
    {
        return $this->isMember($user, $model)
            && $this->roleOf($user, $model) === OrganizationRole::OWNER;
    }

    /**
     * Gesperrte oder geloeschte Konten haben keinen Zugriff.
     */
    protected function isUsableAccount(User $user): bool
    {
        return $user->getAttribute('deleted_at') === null;
    }
}
