<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BillingRunStatus;
use App\Models\BillingRun;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationAccess;

/**
 * Zugriff auf Abrechnungslaeufe.
 *
 * Ein bezahlter oder finalisierter Lauf ist nicht mehr aenderbar. Die Zahlung und
 * die Finalisierung erfordern zusaetzlich eine Rolle mit Zahlungsrecht. Die
 * Freigabe zur Finalisierung setzt eine bestaetigte Zahlung voraus und wird
 * ausserdem serverseitig gegen offene Blocker geprueft.
 */
class BillingRunPolicy
{
    use ResolvesOrganizationAccess;

    public function viewAny(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function view(User $user, BillingRun $billingRun): bool
    {
        return $this->isUsableAccount($user) && $this->isMember($user, $billingRun);
    }

    public function create(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function update(User $user, BillingRun $billingRun): bool
    {
        if (! $this->mayWrite($user, $billingRun)) {
            return false;
        }

        return $this->status($billingRun)?->isEditable() === true;
    }

    public function delete(User $user, BillingRun $billingRun): bool
    {
        if (! $this->mayWrite($user, $billingRun)) {
            return false;
        }

        return $this->status($billingRun)?->isPaid() !== true;
    }

    public function uploadDocuments(User $user, BillingRun $billingRun): bool
    {
        return $this->update($user, $billingRun);
    }

    public function calculate(User $user, BillingRun $billingRun): bool
    {
        return $this->update($user, $billingRun);
    }

    public function checkout(User $user, BillingRun $billingRun): bool
    {
        if (! $this->mayManageBilling($user, $billingRun)) {
            return false;
        }

        return match ($this->status($billingRun)) {
            BillingRunStatus::PREVIEW_READY, BillingRunStatus::CHECKOUT_PENDING => true,
            default => false,
        };
    }

    public function finalize(User $user, BillingRun $billingRun): bool
    {
        if (! $this->mayManageBilling($user, $billingRun)) {
            return false;
        }

        return $this->status($billingRun)?->isPaid() === true;
    }

    public function restore(User $user, BillingRun $billingRun): bool
    {
        return $this->isOwner($user, $billingRun);
    }

    public function forceDelete(User $user, BillingRun $billingRun): bool
    {
        return false;
    }

    private function status(BillingRun $billingRun): ?BillingRunStatus
    {
        $status = $billingRun->getAttribute('status');

        return $status instanceof BillingRunStatus ? $status : null;
    }
}
