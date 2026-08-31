<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Models\User;

/**
 * Vorbereitung der optionalen TOTP-Zwei-Faktor-Authentifizierung.
 *
 * Vorgabe des Masterprompts, Abschnitt 8.1: optional TOTP-2FA, fuer Admins
 * verpflichtend. Die Freischaltung erfolgt in Phase 5.
 *
 * ABSICHTLICH OHNE KRYPTOGRAFIE
 *
 * Diese Klasse enthaelt bewusst keinen Schluesselgenerator, keine
 * Base32-Kodierung und keine Codepruefung. Ein halbfertiger Zweitfaktor ist
 * gefaehrlicher als kein Zweitfaktor, weil er ein Sicherheitsversprechen
 * abgibt, das er nicht haelt. Enthalten sind nur die bereits im Datenmodell
 * vorhandenen Anknuepfungspunkte und die verbindlichen Parameter, damit die
 * Umsetzung spaeter nicht neu entschieden werden muss.
 *
 * DATENMODELL, bereits vorhanden
 *
 *   users.two_factor_secret        Text, anwendungsseitig verschluesselt
 *                                  (Cast "encrypted" am Modell User)
 *   users.two_factor_confirmed_at  DATETIME, gesetzt nach der ersten
 *                                  erfolgreichen Codepruefung
 *
 * ABLAUF, verbindlich fuer die spaetere Umsetzung
 *
 *   1. Einrichtung starten: Geheimnis erzeugen, verschluesselt speichern,
 *      two_factor_confirmed_at bleibt leer.
 *   2. QR-Code und Klartextgeheimnis genau einmal anzeigen.
 *   3. Der Nutzer bestaetigt mit einem gueltigen Code. Erst dann wird
 *      two_factor_confirmed_at gesetzt und der Faktor ist aktiv.
 *   4. Wiederherstellungscodes einmalig anzeigen, nur als Hash speichern.
 *   5. Deaktivierung nur nach erneuter Passworteingabe.
 *   6. Fuer Adminrollen ist der bestaetigte Faktor Pflicht. Das Gate
 *      access-admin in bootstrap/app.php ist dann um die Pruefung von
 *      two_factor_confirmed_at zu erweitern.
 *
 * TODO Phase 5: Umsetzung mit einer geprueften TOTP-Bibliothek. Vorgaben:
 * RFC 6238, HMAC-SHA1, 6 Stellen, 30 Sekunden Zeitfenster, Toleranz von einem
 * Schritt in beide Richtungen, Ratenbegrenzung der Codepruefung, Ausgabe der
 * Wiederherstellungscodes genau einmal.
 */
class TwoFactorPreparation
{
    public const ALGORITHMUS = 'SHA1';

    public const STELLEN = 6;

    public const ZEITFENSTER_SEKUNDEN = 30;

    /**
     * Toleranz in Zeitfenstern, um Uhrabweichungen auszugleichen.
     */
    public const TOLERANZ_SCHRITTE = 1;

    public const AUSSTELLER = 'Smart Abrechnen';

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

        return 'In Vorbereitung';
    }
}
