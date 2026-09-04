<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

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

    public const AKTION_VERSAND_FEHLGESCHLAGEN = 'email.failed';

    /**
     * Neutrale Meldung, wenn eine Kontomail gerade nicht versendet werden
     * konnte. Sie verraet nichts ueber das Konto.
     */
    public const MELDUNG_VERSAND_FEHLGESCHLAGEN = 'Die E-Mail konnte gerade nicht versendet werden. Bitte '
        .'fordern Sie den Link in einigen Minuten erneut an.';

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
     *
     * Ein Zustellfehler, etwa ein nicht erreichbarer SMTP-Server, fuehrt nicht
     * zu einer Fehlerseite: Das Konto ist zu diesem Zeitpunkt bereits angelegt
     * und der Nutzer kann den Link spaeter erneut anfordern. Der Fehler wird
     * protokolliert, ohne Adresse und ohne Link.
     *
     * @return bool true, wenn versendet oder nichts zu versenden war
     */
    public function send(User $user): bool
    {
        if ($this->isVerified($user)) {
            return true;
        }

        try {
            $user->notify(new VerifyEmailAddress($this->signedUrl($user)));

            return true;
        } catch (Throwable $fehler) {
            Log::warning('Bestaetigungsmail konnte nicht versendet werden.', [
                'user_id' => $user->getKey(),
                'fehler' => $fehler::class,
            ]);

            $this->audit->record(
                action: self::AKTION_VERSAND_FEHLGESCHLAGEN,
                subject: $user,
                actor: $user,
                metadata: ['template' => 'verifizierung', 'fehler' => $fehler::class],
            );

            return false;
        }
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

        // Nur ein unbestaetigtes Konto wechselt auf AKTIV. Eine Sperre oder
        // Loeschvormerkung bleibt bestehen: Der Link ist ohne Anmeldung
        // erreichbar und bis zu 60 Minuten gueltig, er darf eine inzwischen
        // gesetzte Sperre nicht aufheben.
        $status = $user->getAttribute('status');
        $werte = ['email_verified_at' => now()];

        if ($status === UserStatus::UNBESTAETIGT) {
            $werte['status'] = UserStatus::AKTIV;
        }

        $user->forceFill($werte)->save();

        $this->audit->record(
            action: 'account.email_verified',
            subject: $user,
            actor: $user,
            metadata: ['status' => $status instanceof UserStatus ? $status->value : null],
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
