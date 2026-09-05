<?php

declare(strict_types=1);

namespace App\Application\Install;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Models\AdminRoleAssignment;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Legt den ersten Administrator an oder erteilt einer bestehenden Kennung die
 * Adminrolle (Masterprompt 20).
 *
 * VERBINDLICH
 *
 *  1. Idempotent: Existiert die Adresse, wird kein zweites Konto angelegt.
 *     Fehlt die Adminrolle, wird sie ergaenzt; ist sie widerrufen, wird eine
 *     neue Zuweisung angelegt, damit der Widerruf nachvollziehbar bleibt.
 *  2. Das Einmalpasswort wird genau einmal an den Aufrufer zurueckgegeben und
 *     nirgends protokolliert. Es entsteht nur fuer ein NEUES Konto; ein
 *     bestehendes Passwort wird nie ueberschrieben.
 *  3. Der Zweitfaktor wird hier nicht gesetzt. Fuer Adminrollen ist er
 *     verpflichtend; die Anmeldung fuehrt die Kennung direkt auf die
 *     Einrichtung (App\Application\Account\LoginDestination,
 *     App\Http\Middleware\RequireAdminTwoFactor).
 */
final class CreateAdministrator
{
    public const int PASSWORD_LENGTH = 24;

    public function __construct(private readonly ConnectionInterface $db) {}

    public function execute(string $email, ?string $name = null, AdminRole $role = AdminRole::ADMIN): AdministratorCreated
    {
        $email = Str::lower(trim($email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Bitte geben Sie eine gueltige E-Mail-Adresse an.');
        }

        return $this->db->transaction(function () use ($email, $name, $role): AdministratorCreated {
            /** @var User|null $user */
            $user = User::query()->withTrashed()->where('email', $email)->lockForUpdate()->first();

            $password = null;
            $created = false;

            if ($user === null) {
                $password = Str::password(self::PASSWORD_LENGTH, symbols: false);
                $created = true;

                /** @var User $user */
                $user = User::query()->create([
                    'name' => $name !== null && trim($name) !== '' ? trim($name) : 'Administration',
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => Hash::make($password),
                    'status' => UserStatus::AKTIV,
                    'locale' => 'de',
                    'timezone' => 'Europe/Berlin',
                ]);
            } elseif ($user->getAttribute('deleted_at') !== null) {
                throw new InvalidArgumentException(
                    'Zu dieser Adresse existiert ein geloeschtes Konto. Es wird nicht automatisch wiederhergestellt.',
                );
            }

            $roleGranted = false;

            if (! $user->hasAdminRole($role)) {
                AdminRoleAssignment::query()->create([
                    'user_id' => $user->getKey(),
                    'role' => $role,
                    'granted_at' => now(),
                    'reason' => 'Inbetriebnahme ueber smartabrechnen:admin:create',
                ]);
                $roleGranted = true;
            }

            return new AdministratorCreated(
                $user,
                $created,
                $roleGranted,
                $password,
                $user->getAttribute('two_factor_confirmed_at') !== null,
            );
        });
    }
}
