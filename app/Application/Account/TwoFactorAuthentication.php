<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Security\RecoveryCodeGenerator;
use App\Domain\Security\TimeBasedOneTimePassword;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Einrichtung, Pruefung und Abschaltung des TOTP-Zweitfaktors.
 *
 * Vorgabe des Masterprompts, Abschnitt 8.1: TOTP-2FA ist fuer Kunden optional
 * und fuer Adminrollen verpflichtend.
 *
 * DATENHALTUNG
 *
 *   users.two_factor_secret            Base32-Geheimnis, anwendungsseitig
 *                                      verschluesselt (Cast "encrypted"). In der
 *                                      Spalte steht ausschliesslich Chiffrat.
 *   users.two_factor_confirmed_at      gesetzt nach der ersten erfolgreichen
 *                                      Codepruefung. Erst dann gilt der Faktor
 *                                      als aktiv.
 *   users.two_factor_recovery_codes    JSON-Liste der EINZELN GEHASHTEN
 *                                      Wiederherstellungscodes. Ein verbrauchter
 *                                      Code wird aus der Liste entfernt und
 *                                      funktioniert damit nicht erneut.
 *
 * ABLAUF
 *
 *   1. beginSetup   Geheimnis erzeugen und verschluesselt speichern,
 *                   two_factor_confirmed_at bleibt leer.
 *   2. confirm      Pruefung eines gueltigen Codes. Erst danach ist der Faktor
 *                   aktiv, und die Wiederherstellungscodes werden genau einmal
 *                   im Klartext zurueckgegeben.
 *   3. verify       Pruefung bei der Anmeldung.
 *   4. consumeRecoveryCode  Ersatzweg, falls das Telefon fehlt.
 *   5. disable      nur mit Passwort und gueltigem Faktor, siehe
 *                   App\Http\Controllers\Auth\TwoFactorSetupController.
 *
 * PROTOKOLLIERUNG
 *
 * Aktivierung, Abschaltung, erfolgreiche und fehlgeschlagene Codepruefung sowie
 * die Verwendung eines Wiederherstellungscodes werden ueber den AuditRecorder
 * festgehalten. In den Protokolleintrag gelangen ausschliesslich technische
 * Kennzahlen, niemals das Geheimnis und niemals der eingegebene Code.
 */
class TwoFactorAuthentication
{
    /**
     * Sitzungsschluessel des noch offenen zweiten Faktors nach erfolgreicher
     * Passwortpruefung. Solange dieser Schluessel gesetzt ist, gilt die Sitzung
     * als nicht vollstaendig authentifiziert.
     */
    public const string SESSION_OFFENER_NUTZER = 'zwei_faktor.offener_nutzer';

    /**
     * Sitzungsschluessel des Wunsches "angemeldet bleiben" aus dem ersten
     * Schritt der Anmeldung.
     */
    public const string SESSION_MERKEN = 'zwei_faktor.merken';

    /**
     * Sitzungsschluessel der genau einmal anzuzeigenden Wiederherstellungscodes.
     */
    public const string SESSION_CODES = 'zwei_faktor.wiederherstellungscodes';

    public const string AKTION_AKTIVIERT = 'account.two_factor_enabled';

    public const string AKTION_DEAKTIVIERT = 'account.two_factor_disabled';

    public const string AKTION_ERFOLG = 'account.two_factor_succeeded';

    public const string AKTION_FEHLSCHLAG = 'account.two_factor_failed';

    public const string AKTION_WIEDERHERSTELLUNG = 'account.two_factor_recovery_used';

    public function __construct(
        private readonly TimeBasedOneTimePassword $totp,
        private readonly AuditRecorder $audit,
    ) {}

    public function isConfirmed(User $user): bool
    {
        return $user->getAttribute('two_factor_confirmed_at') !== null;
    }

    /**
     * Einrichtung begonnen, aber noch nicht bestaetigt.
     */
    public function isPending(User $user): bool
    {
        return $this->secret($user) !== null && ! $this->isConfirmed($user);
    }

    /**
     * Erzeugt ein neues Geheimnis und verwirft eine begonnene Einrichtung.
     *
     * Ein bereits bestaetigter Faktor wird NICHT ueberschrieben. Wer sein
     * Geheimnis wechseln will, schaltet den Faktor zuerst ab.
     */
    public function beginSetup(User $user): string
    {
        if ($this->isConfirmed($user)) {
            $vorhanden = $this->secret($user);

            if ($vorhanden !== null) {
                return $vorhanden;
            }
        }

        $geheimnis = TimeBasedOneTimePassword::generateSecret();

        $user->forceFill([
            'two_factor_secret' => $geheimnis,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return $geheimnis;
    }

    /**
     * Bestaetigt die Einrichtung mit einem gueltigen Code.
     *
     * @return list<string>|null Wiederherstellungscodes im Klartext, genau
     *                           einmal. null, wenn der Code nicht passt.
     */
    public function confirm(User $user, string $code, ?int $timestamp = null): ?array
    {
        $geheimnis = $this->secret($user);

        if ($geheimnis === null || $this->isConfirmed($user)) {
            return null;
        }

        if (! $this->totp->verify($geheimnis, $code, $timestamp)) {
            $this->protokolliere($user, self::AKTION_FEHLSCHLAG, ['schritt' => 'einrichtung']);

            return null;
        }

        $codes = RecoveryCodeGenerator::generate();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(
                static fn (string $klartext): string => Hash::make($klartext),
                $codes,
            ),
        ])->save();

        $this->protokolliere($user, self::AKTION_AKTIVIERT, [
            'wiederherstellungscodes' => count($codes),
        ]);

        return $codes;
    }

    /**
     * Prueft einen Code eines aktiven Faktors, etwa bei der Anmeldung.
     */
    public function verify(User $user, string $code, ?int $timestamp = null): bool
    {
        $geheimnis = $this->secret($user);

        if ($geheimnis === null) {
            return false;
        }

        $gueltig = $this->totp->verify($geheimnis, $code, $timestamp);

        $this->protokolliere(
            $user,
            $gueltig ? self::AKTION_ERFOLG : self::AKTION_FEHLSCHLAG,
            ['verfahren' => 'totp'],
        );

        return $gueltig;
    }

    /**
     * Prueft einen Wiederherstellungscode und entwertet ihn bei Erfolg.
     */
    public function consumeRecoveryCode(User $user, string $eingabe): bool
    {
        $eingabe = RecoveryCodeGenerator::normalize($eingabe);

        if ($eingabe === '') {
            return false;
        }

        $hashes = $this->recoveryHashes($user);

        foreach ($hashes as $index => $hash) {
            if (! Hash::check($eingabe, $hash)) {
                continue;
            }

            unset($hashes[$index]);
            $verbleibend = array_values($hashes);

            $user->forceFill([
                'two_factor_recovery_codes' => $verbleibend === [] ? [] : $verbleibend,
            ])->save();

            $this->protokolliere($user, self::AKTION_WIEDERHERSTELLUNG, [
                'verbleibende_codes' => count($verbleibend),
            ]);

            return true;
        }

        $this->protokolliere($user, self::AKTION_FEHLSCHLAG, ['verfahren' => 'wiederherstellungscode']);

        return false;
    }

    /**
     * Prueft Code oder Wiederherstellungscode.
     */
    public function verifyCodeOrRecovery(User $user, string $eingabe, ?int $timestamp = null): bool
    {
        $eingabe = trim($eingabe);

        if (preg_match('/^\d{'.TimeBasedOneTimePassword::STELLEN.'}$/', str_replace(' ', '', $eingabe)) === 1) {
            return $this->verify($user, $eingabe, $timestamp);
        }

        return $this->consumeRecoveryCode($user, $eingabe);
    }

    /**
     * Schaltet den Zweitfaktor ab und verwirft Geheimnis und Codes.
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        $this->protokolliere($user, self::AKTION_DEAKTIVIERT);
    }

    /**
     * Anzahl der noch nutzbaren Wiederherstellungscodes.
     */
    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->recoveryHashes($user));
    }

    public function otpauthUri(User $user): string
    {
        $geheimnis = $this->secret($user) ?? '';
        $konto = $user->getAttribute('email');

        return $this->totp->otpauthUri(
            TwoFactorPreparation::AUSSTELLER,
            is_string($konto) ? $konto : 'konto',
            $geheimnis,
        );
    }

    /**
     * Geheimnis in Vierergruppen zum Abtippen.
     */
    public function formattedSecret(User $user): string
    {
        return $this->totp->formatSecret($this->secret($user) ?? '');
    }

    public function secret(User $user): ?string
    {
        $wert = $user->getAttribute('two_factor_secret');

        return is_string($wert) && $wert !== '' ? $wert : null;
    }

    /**
     * @return list<string>
     */
    private function recoveryHashes(User $user): array
    {
        $wert = $user->getAttribute('two_factor_recovery_codes');

        if (! is_array($wert)) {
            return [];
        }

        $hashes = [];

        foreach ($wert as $eintrag) {
            if (is_string($eintrag) && $eintrag !== '') {
                $hashes[] = $eintrag;
            }
        }

        return $hashes;
    }

    /**
     * @param  array<string, scalar|null>  $metadaten
     */
    private function protokolliere(User $user, string $aktion, array $metadaten = []): void
    {
        // In den Protokolleintrag gelangen ausschliesslich technische
        // Kennzahlen. Weder das Geheimnis noch der eingegebene Code werden
        // uebergeben.
        $this->audit->record(
            action: $aktion,
            subject: $user,
            actor: $user,
            metadata: $metadaten,
        );
    }
}
