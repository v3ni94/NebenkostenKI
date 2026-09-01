<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Security\TimeBasedOneTimePassword;
use App\Models\User;

/**
 * Feste Parameter und Statusanzeige der TOTP-Zwei-Faktor-Authentifizierung.
 *
 * Vorgabe des Masterprompts, Abschnitt 8.1: optional TOTP-2FA, fuer Admins
 * verpflichtend.
 *
 * ZUSTAENDIGKEIT
 *
 * Diese Klasse haelt ausschliesslich die verbindlichen Parameter und liefert
 * den Anzeigetext fuer den Kontobereich. Die Kryptografie liegt in
 * App\Domain\Security\TimeBasedOneTimePassword, der fachliche Ablauf in
 * App\Application\Account\TwoFactorAuthentication.
 *
 * DATENMODELL
 *
 *   users.two_factor_secret          Base32-Geheimnis, anwendungsseitig
 *                                    verschluesselt (Cast "encrypted")
 *   users.two_factor_confirmed_at    gesetzt nach der ersten erfolgreichen
 *                                    Codepruefung
 *   users.two_factor_recovery_codes  einzeln gehashte Wiederherstellungscodes
 */
class TwoFactorPreparation
{
    public const string ALGORITHMUS = 'SHA1';

    public const int STELLEN = TimeBasedOneTimePassword::STELLEN;

    public const int ZEITFENSTER_SEKUNDEN = TimeBasedOneTimePassword::ZEITFENSTER_SEKUNDEN;

    /**
     * Toleranz in Zeitfenstern, um Uhrabweichungen auszugleichen.
     */
    public const int TOLERANZ_SCHRITTE = TimeBasedOneTimePassword::TOLERANZ_SCHRITTE;

    public const string AUSSTELLER = 'Smart Abrechnen';

    /**
     * Ist der Zweitfaktor fuer diesen Nutzer bereits bestaetigt?
     */
    public function isConfirmed(User $user): bool
    {
        return $user->getAttribute('two_factor_confirmed_at') !== null;
    }

    /**
     * Ist die Einrichtung begonnen, aber noch nicht bestaetigt?
     */
    public function isPending(User $user): bool
    {
        return $user->getAttribute('two_factor_secret') !== null
            && ! $this->isConfirmed($user);
    }

    /**
     * Anzeigetext fuer den Kontobereich.
     */
    public function statusLabel(User $user): string
    {
        if ($this->isConfirmed($user)) {
            return 'Aktiv';
        }

        if ($this->isPending($user)) {
            return 'Einrichtung begonnen';
        }

        return 'Nicht aktiv';
    }
}
