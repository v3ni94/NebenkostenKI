<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Models\User;

/**
 * Startseite nach erfolgreicher Anmeldung.
 *
 * Kundennutzer landen auf dem Dashboard der Anwendung. Interne Kennungen
 * (Tabelle admin_roles) haben in der Regel keinen Mandanten und wuerden dort
 * mit 403 enden. Sie werden deshalb direkt gefuehrt:
 *
 *   - ohne bestaetigten Zweitfaktor auf die Einrichtung des Zweitfaktors, die
 *     fuer Adminrollen verpflichtend ist (RequireAdminTwoFactor),
 *   - mit Zweitfaktor auf das Dashboard des Adminbereichs.
 *
 * Eine interne Kennung, die zusaetzlich Mitglied eines Mandanten ist, behaelt
 * das Kundendashboard als Startseite.
 */
final class LoginDestination
{
    public function __construct(private readonly TwoFactorAuthentication $zweiFaktor) {}

    public function for(User $user): string
    {
        if (! $user->isStaff() || $user->organizationIds() !== []) {
            return route('portal.dashboard');
        }

        if (! $this->zweiFaktor->isConfirmed($user)) {
            return route('two-factor.setup');
        }

        return route('admin.dashboard');
    }
}
