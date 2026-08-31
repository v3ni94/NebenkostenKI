<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * E-Mail-Verifizierung ohne Laravel-Standardvertrag.
 *
 * App\Models\User implementiert MustVerifyEmail derzeit nicht. Das Modell
 * gehoert einem anderen Arbeitspaket und wird hier nicht geaendert. Der Ablauf
 * arbeitet deshalb direkt auf der Spalte email_verified_at und bildet den
 * Laravel-Standard nach:
 *
 *  - Der Bestaetigungslink ist eine signierte URL mit kurzer Gueltigkeit.
 *  - Der Link enthaelt zusaetzlich den SHA-1 der E-Mail-Adresse. Aendert der
 *    Nutzer seine Adresse, verfaellt ein noch offener Link sofort.
 *  - Die Bestaetigung ist idempotent. Ein zweiter Aufruf fuehrt nicht zu einem
 *    Fehler.
 *
 * Mit der Bestaetigung wechselt der Kontostatus von UNBESTAETIGT auf AKTIV. Nur
 * aktive Konten erhalten Erinnerungen (Masterprompt 17.2).
 */
class EmailVerification
{
    /**
     * Gueltigkeit des Bestaetigungslinks in Minuten.
     */
    public const LINK_GUELTIGKEIT_MINUTEN = 60;

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Signierte, kurzlebige Bestaetigungs-URL.
     */
    public function signedUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(self::LINK_GUELTIGKEIT_MINUTEN),
            [
                'user' => $user->getKey(),
                'hash' => $this->hash($user),
            ]
        );
    }

    public function hash(User $user): string
    {
        $email = $user->getAttribute('email');

        return sha1(is_string($email) ? $email : '');
    }

    public function isVerified(User $user): bool
    {
        return $user->getAttribute('email_verified_at') !== null;
    }

    /**
     * Versendet den Bestaetigungslink an die aktuelle Adresse des Nutzers.
     */
    public function send(User $user): void
    {
        if ($this->isVerified($user)) {
            return;
        }

        $user->notify(new VerifyEmailAddress($this->signedUrl($user)));
    }

    /**
     * Bestaetigt die Adresse. Gibt false zurueck, wenn der Hash nicht passt.
     */
    public function markVerified(User $user, string $hash): bool
    {
        if (! hash_equals($this->hash($user), $hash)) {
            return false;
        }

        if ($this->isVerified($user)) {
            return true;
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'status' => UserStatus::AKTIV,
        ])->save();

        $this->audit->record(
            action: 'account.email_verified',
            subject: $user,
            actor: $user,
        );

        return true;
    }

    /**
     * Setzt die Bestaetigung zurueck, etwa nach einer Adressaenderung.
     */
    public function reset(User $user): void
    {
        $user->forceFill([
            'email_verified_at' => null,
            'status' => UserStatus::UNBESTAETIGT,
        ])->save();
    }
}
