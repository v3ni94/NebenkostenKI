<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UnitStatementStatus;
use App\Models\UnitStatement;
use App\Models\User;
use App\Policies\Concerns\ResolvesOrganizationAccess;

/**
 * Zugriff auf Mieterabrechnungen.
 *
 * Die Vorschau ist nur authentifiziert und mit Wasserzeichen abrufbar. Die
 * Finalversion setzt den Status FINAL voraus, der erst nach bestaetigter Zahlung
 * gesetzt wird.
 */
class UnitStatementPolicy
{
    use ResolvesOrganizationAccess;

    public function viewAny(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function view(User $user, UnitStatement $unitStatement): bool
    {
        return $this->isUsableAccount($user) && $this->isMember($user, $unitStatement);
    }

    public function create(User $user): bool
    {
        return $this->isUsableAccount($user);
    }

    public function update(User $user, UnitStatement $unitStatement): bool
    {
        if (! $this->mayWrite($user, $unitStatement)) {
            return false;
        }

        return $this->status($unitStatement) !== UnitStatementStatus::FINAL;
    }

    public function delete(User $user, UnitStatement $unitStatement): bool
    {
        return $this->update($user, $unitStatement);
    }

    public function downloadPreview(User $user, UnitStatement $unitStatement): bool
    {
        return $this->view($user, $unitStatement);
    }

    public function downloadFinal(User $user, UnitStatement $unitStatement): bool
    {
        return $this->view($user, $unitStatement)
            && $this->status($unitStatement) === UnitStatementStatus::FINAL;
    }

    public function forceDelete(User $user, UnitStatement $unitStatement): bool
    {
        return false;
    }

    private function status(UnitStatement $unitStatement): ?UnitStatementStatus
    {
        $status = $unitStatement->getAttribute('status');

        return $status instanceof UnitStatementStatus ? $status : null;
    }
}
