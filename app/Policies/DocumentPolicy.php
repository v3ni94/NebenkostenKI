<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationAccess;

/**
 * Zugriff auf Dokumentmetadaten und ausgelesene Inhaltsdaten.
 *
 * Ein Download der Originaldatei ist bewusst nicht vorgesehen. Originale werden
 * nach der Auswertung geloescht und stehen im Konto nicht zum Abruf bereit.
 */
class DocumentPolicy
{
    use ResolvesOrganizationAccess;

    public function viewAny(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function view(User $user, Document $document): bool
    {
        return $this->isUsableAccount($user) && $this->isMember($user, $document);
    }

    public function create(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->isUsableAccount($user) && $this->mayWrite($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->isUsableAccount($user) && $this->mayWrite($user, $document);
    }

    /**
     * Es gibt keinen Abruf der Originaldatei, auch nicht fuer Inhaber.
     */
    public function downloadOriginal(User $user, Document $document): bool
    {
        return false;
    }

    public function forceDelete(User $user, Document $document): bool
    {
        return false;
    }
}
