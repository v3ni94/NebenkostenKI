<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Landlord;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationAccess;

/**
 * Vermieter als Absender der Mieterabrechnung.
 *
 * Der Vermieter wird ausschliesslich ueber sein Objekt erreicht. Der
 * Controller prueft deshalb zusaetzlich die PropertyPolicy des Objekts; hier
 * greift der objektbezogene Mandantencheck am Vermieterdatensatz selbst.
 */
class LandlordPolicy
{
    use ResolvesOrganizationAccess;

    public function view(User $user, Landlord $landlord): bool
    {
        return $this->isUsableAccount($user) && $this->isMember($user, $landlord);
    }

    public function create(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function update(User $user, Landlord $landlord): bool
    {
        return $this->isUsableAccount($user) && $this->mayWrite($user, $landlord);
    }

    public function delete(User $user, Landlord $landlord): bool
    {
        return $this->isUsableAccount($user) && $this->mayWrite($user, $landlord);
    }
}
