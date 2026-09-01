<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Nutzerverwaltung des Adminbereichs (Masterprompt 20).
 *
 * SPERREN
 *
 * Eine Sperre setzt den Kontostatus auf GESPERRT, entzieht das
 * Angemeldet-bleiben-Merkmal und beendet alle offenen Sitzungen des Nutzers.
 * Der Zugang zum Adminbereich wird zusaetzlich in
 * App\Http\Middleware\RequireAdminTwoFactor geprueft, damit eine gesperrte
 * interne Kennung sofort ausgeschlossen ist.
 *
 * OFFENER PUNKT, im Uebergabebericht vermerkt: Die Anmeldung im Kundenbereich
 * prueft den Kontostatus derzeit nicht. Die Pruefung gehoert in das
 * Auth-Paket und ist dort nachzuziehen. Diese Klasse tut alles, was ohne
 * Aenderung an fremden Dateien moeglich ist: Status, Merkmal und Sitzungen.
 *
 * PASSWORT-RESET
 *
 * Der Adminbereich setzt niemals ein Passwort. Er loest ausschliesslich den
 * regulaeren Zurücksetzen-Link an die hinterlegte Adresse aus.
 */
final class UserAdministration
{
    public function __construct(private readonly AdminAuditRecorder $audit) {}

    public function lock(User $user, User $actor, string $reason): void
    {
        $user->forceFill([
            'status' => UserStatus::GESPERRT,
            'remember_token' => null,
        ])->save();

        $this->terminateSessions($user);

        $this->audit->record(
            action: 'admin.user.locked',
            actor: $actor,
            subject: $user,
            reason: $reason,
        );
    }

    public function unlock(User $user, User $actor, string $reason): void
    {
        $status = $user->getAttribute('email_verified_at') === null
            ? UserStatus::UNBESTAETIGT
            : UserStatus::AKTIV;

        $user->forceFill(['status' => $status])->save();

        $this->audit->record(
            action: 'admin.user.unlocked',
            actor: $actor,
            subject: $user,
            metadata: ['neuer_status' => $status->value],
            reason: $reason,
        );
    }

    public function isLocked(User $user): bool
    {
        return $user->getAttribute('status') === UserStatus::GESPERRT;
    }

    /**
     * Loest den regulaeren Zurücksetzen-Link aus. Rueckgabe ist der
     * Statusschluessel des Passwortbrokers.
     */
    public function sendPasswordReset(User $user, User $actor): string
    {
        $email = (string) $user->getAttribute('email');

        $status = Password::broker()->sendResetLink(['email' => $email]);

        $this->audit->record(
            action: 'admin.user.password_reset_requested',
            actor: $actor,
            subject: $user,
            metadata: ['ergebnis' => $status],
        );

        return $status;
    }

    /**
     * Beendet alle Datenbanksitzungen des Nutzers. Bei einem anderen
     * Sitzungstreiber ist das ein Nullvorgang; der Statuswechsel greift
     * trotzdem.
     */
    private function terminateSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table('sessions')->where('user_id', $user->getKey())->delete();
    }
}
