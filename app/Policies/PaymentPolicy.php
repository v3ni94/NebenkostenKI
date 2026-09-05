<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationAccess;

/**
 * Zugriff auf Zahlungen. Erstattungen erfolgen ausschliesslich intern.
 */
class PaymentPolicy
{
    use ResolvesOrganizationAccess;

    public function viewAny(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->isUsableAccount($user) && $this->isMember($user, $payment);
    }

    public function create(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }

    public function refund(User $user, Payment $payment): bool
    {
        return false;
    }
}
