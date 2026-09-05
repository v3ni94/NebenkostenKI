<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Application\Account\TwoFactorAuthentication;
use App\Enums\UserStatus;
use App\Http\Requests\GermanFormRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Anmeldung mit Ratenbegrenzung und Schutz gegen Credential Stuffing.
 *
 * Vorgabe des Masterprompts, Abschnitt 8.1 und ARCHITECTURE.md T9.
 *
 * MASSNAHMEN
 *
 *  1. Ratenbegrenzung je Kombination aus E-Mail-Adresse und IP-Adresse. Nach
 *     fuenf Fehlversuchen wird der Zugang eine Minute gesperrt, der Hinweis
 *     nennt die Wartezeit in deutscher Sprache. Der Zaehler wird nach einer
 *     erfolgreichen Anmeldung geloescht.
 *  2. Zusaetzlich eine Begrenzung allein je IP-Adresse. Ein Angreifer, der
 *     viele verschiedene Adressen mit demselben Passwort probiert, laeuft
 *     sonst nie in ein Limit.
 *  3. Generische Fehlermeldung. Sie sagt nie, ob die E-Mail-Adresse existiert.
 *     Die Meldung ist bei unbekannter Adresse und bei falschem Passwort
 *     identisch.
 *  4. Kuenstliche Verzoegerung von etwa 250 Millisekunden bei jedem
 *     Fehlversuch. Sie verlangsamt automatisierte Versuche und verwischt den
 *     Laufzeitunterschied zwischen unbekannter Adresse und falschem Passwort.
 *     Wegen des Argon2id-Hashings ist dieser Unterschied ohnehin gering, die
 *     Verzoegerung schliesst ihn ab.
 *
 * ZWEITER FAKTOR (Masterprompt 8.1)
 *
 * Ist fuer das Konto ein bestaetigter Zweitfaktor hinterlegt, endet dieser
 * Schritt bewusst OHNE Anmeldung. Die Sitzung merkt sich nur die Kennung des
 * Kontos, der Nutzer bleibt bis zum gueltigen Code ein Gast und erreicht damit
 * keinen geschuetzten Bereich. Den zweiten Schritt fuehrt
 * App\Http\Controllers\Auth\TwoFactorChallengeController mit eigener
 * Ratenbegrenzung. Die Statuspruefung des Kontos bleibt davon unberuehrt, sie
 * laeuft weiterhin unmittelbar nach der Passwortpruefung.
 */
class LoginRequest extends GermanFormRequest
{
    /**
     * Zulaessige Fehlversuche je E-Mail-Adresse und IP.
     */
    public const VERSUCHE_JE_KONTO = 5;

    /**
     * Zulaessige Fehlversuche je IP-Adresse ueber alle Konten.
     */
    public const VERSUCHE_JE_IP = 20;

    public const SPERRE_SEKUNDEN = 60;

    public const SPERRE_IP_SEKUNDEN = 300;

    /**
     * Steht nach authenticate() der zweite Faktor noch aus?
     */
    private bool $zweitfaktorOffen = false;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'E-Mail-Adresse',
            'password' => 'Passwort',
        ];
    }

    /**
     * Prueft die Zugangsdaten.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $erfolg = Auth::attempt(
            [
                'email' => Str::lower((string) $this->string('email')),
                'password' => (string) $this->string('password'),
            ],
            $this->boolean('remember')
        );

        if (! $erfolg) {
            RateLimiter::hit($this->throttleKey(), self::SPERRE_SEKUNDEN);
            RateLimiter::hit($this->ipThrottleKey(), self::SPERRE_IP_SEKUNDEN);

            // Verzoegerung gegen automatisierte Versuche und gegen die
            // Auswertung von Laufzeitunterschieden.
            usleep(250_000);

            throw ValidationException::withMessages([
                'email' => 'Die Zugangsdaten sind nicht richtig. Bitte prüfen Sie E-Mail-Adresse und Passwort.',
            ]);
        }

        $this->ensureAccountIsUsable();

        RateLimiter::clear($this->throttleKey());

        $this->ensureSecondFactor();
    }

    /**
     * Verlangt bei aktivem Zweitfaktor den zweiten Schritt.
     *
     * Der Nutzer wird dafuer wieder abgemeldet. Die Sitzungsdaten bleiben
     * erhalten, damit der zweite Schritt weiss, welches Konto gemeint ist.
     */
    private function ensureSecondFactor(): void
    {
        $nutzer = Auth::user();

        if (! $nutzer instanceof User) {
            return;
        }

        $zweiFaktor = app(TwoFactorAuthentication::class);

        if (! $zweiFaktor->isConfirmed($nutzer)) {
            return;
        }

        $this->session()->put(TwoFactorAuthentication::SESSION_OFFENER_NUTZER, $nutzer->getKey());
        $this->session()->put(TwoFactorAuthentication::SESSION_MERKEN, $this->boolean('remember'));

        Auth::guard('web')->logout();

        $this->zweitfaktorOffen = true;
    }

    public function zweitfaktorErforderlich(): bool
    {
        return $this->zweitfaktorOffen;
    }

    /**
     * Prueft den Kontostatus NACH erfolgreicher Passwortpruefung.
     *
     * Auth::attempt prueft ausschliesslich E-Mail und Passwort. Ohne diese
     * zusaetzliche Pruefung koennte sich ein gesperrtes oder zur Loeschung
     * vorgemerktes Konto weiterhin anmelden, weil das Sperren im Adminbereich
     * nur den Status setzt, das Merken-Token entzieht und laufende Sitzungen
     * beendet. Ein neuer Anmeldeversuch mit gueltigem Passwort waere sonst
     * erfolgreich.
     *
     * Die Pruefung erfolgt bewusst erst nach der Passwortpruefung: Vorher
     * wuerde die Meldung verraten, ob zu einer Adresse ein Konto existiert.
     *
     * @throws ValidationException
     */
    private function ensureAccountIsUsable(): void
    {
        $nutzer = Auth::user();

        $status = $nutzer?->getAttribute('status');

        if ($status === UserStatus::AKTIV || $status === UserStatus::UNBESTAETIGT) {
            return;
        }

        Auth::guard('web')->logout();
        $this->session()->invalidate();
        $this->session()->regenerateToken();

        $meldung = $status === UserStatus::GESPERRT
            ? 'Dieses Konto ist gesperrt. Bitte wenden Sie sich an kontakt@smart-abrechnen.de.'
            : 'Dieses Konto steht nicht zur Verfügung. Bitte wenden Sie sich an kontakt@smart-abrechnen.de.';

        throw ValidationException::withMessages([
            'email' => $meldung,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), self::VERSUCHE_JE_KONTO)) {
            throw ValidationException::withMessages([
                'email' => $this->sperrhinweis(RateLimiter::availableIn($this->throttleKey())),
            ]);
        }

        if (RateLimiter::tooManyAttempts($this->ipThrottleKey(), self::VERSUCHE_JE_IP)) {
            throw ValidationException::withMessages([
                'email' => $this->sperrhinweis(RateLimiter::availableIn($this->ipThrottleKey())),
            ]);
        }
    }

    /**
     * Deutscher Sperrhinweis mit Wartezeit.
     */
    private function sperrhinweis(int $sekunden): string
    {
        if ($sekunden >= 60) {
            $minuten = (int) ceil($sekunden / 60);

            return sprintf(
                'Zu viele Anmeldeversuche. Bitte versuchen Sie es in %d %s erneut.',
                $minuten,
                $minuten === 1 ? 'Minute' : 'Minuten'
            );
        }

        return sprintf(
            'Zu viele Anmeldeversuche. Bitte versuchen Sie es in %d %s erneut.',
            max(1, $sekunden),
            $sekunden === 1 ? 'Sekunde' : 'Sekunden'
        );
    }

    public function throttleKey(): string
    {
        return 'anmeldung:'.Str::transliterate(
            Str::lower((string) $this->string('email')).'|'.$this->ip()
        );
    }

    public function ipThrottleKey(): string
    {
        return 'anmeldung-ip:'.((string) $this->ip());
    }
}
