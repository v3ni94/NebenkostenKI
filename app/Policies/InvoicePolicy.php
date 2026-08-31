<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationAccess;

/**
 * Zugriff auf Leistungsrechnungen der Hausverwaltung Mueller GmbH.
 *
 * Rechnungen sind unveraenderlich. Nach einer Kontoloeschung ist die Rechnung von
 * der Organisation entkoppelt, dann greift die Pruefung auf user_id.
 */
class InvoicePolicy
{
    use ResolvesOrganizationAccess;

    public function viewAny(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if (! $this->isUsableAccount($user)) {
            return false;
        }

        if ($this->isMember($user, $invoice)) {
            return true;
        }

        return $invoice->getAttribute('user_id') === $user->getKey();
    }

    public function download(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}
