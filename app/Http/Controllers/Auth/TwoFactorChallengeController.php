<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Account\AuditRecorder;
use App\Application\Account\LoginDestination;
use App\Application\Account\TwoFactorAuthentication;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Requests\Auth\TwoFactorCodeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Zweiter Schritt der Anmeldung: Eingabe des Codes.
 *
 * SITZUNGSZUSTAND
 *
 * Nach erfolgreicher Passwortpruefung wird der Nutzer bei aktivem Zweitfaktor
 * bewusst NICHT angemeldet. Die Sitzung merkt sich allein die Kennung des
 * Kontos unter TwoFactorAuthentication::SESSION_OFFENER_NUTZER. Solange dieser
 * Schluessel gesetzt ist, gilt die Sitzung als nicht vollstaendig
 * authentifiziert und erreicht keinen geschuetzten Bereich, weil sie fuer die
 * Middleware auth ein Gast ist. Erst der gueltige Code meldet den Nutzer an.
 *
 * KONTOSTATUS
 *
 * Der Status wird in beiden Schritten geprueft. Wird ein Konto zwischen
 * Passwort und Code gesperrt, endet der zweite Schritt mit derselben Meldung
 * wie der erste; der Sitzungsschluessel wird verworfen. Ohne diese Pruefung
 * wuerde eine offene Codeeingabe die Sperre ueberdauern, weil ihre Sitzung
 * noch keinem Nutzer zugeordnet ist und vom Sperren nicht beendet wird.
 *
 * RATENBEGRENZUNG
 *
 * Eigene Bremse je Konto und IP, unabhaengig von der Bremse des ersten
 * Schritts. Nach fuenf Fehlversuchen ist die Eingabe eine Minute gesperrt. Die
 * Route traegt zusaetzlich throttle:10,1 gegen einfache Automatisierung.
 *
 * PROTOKOLLIERUNG
 *
 * Erfolg, Fehlschlag und die Verwendung eines Wiederherstellungscodes
 * protokolliert App\Application\Account\TwoFactorAuthentication, ohne Geheimnis
 * und ohne den eingegebenen Code.
 */
class TwoFactorChallengeController extends Controller
{
    public const int VERSUCHE = 5;

    public const int SPERRE_SEKUNDEN = 60;

    public function __construct(
        private readonly TwoFactorAuthentication $zweiFaktor,
        private readonly AuditRecorder $audit,
        private readonly LoginDestination $destination,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $benutzer = $this->offenerNutzer($request);

        if (! $benutzer instanceof User) {
            return redirect()->route('login');
        }

        if (! $this->kontoNutzbar($benutzer)) {
            return $this->kontoNichtNutzbar($request, $benutzer);
        }

        return view('auth.zwei-faktor-code', [
            'verbleibendeCodes' => $this->zweiFaktor->remainingRecoveryCodes($benutzer),
        ]);
    }

    public function store(TwoFactorCodeRequest $request): RedirectResponse
    {
        $benutzer = $this->offenerNutzer($request);

        if (! $benutzer instanceof User) {
            return redirect()->route('login')->withErrors([
                'code' => 'Die Anmeldung ist abgelaufen. Bitte melden Sie sich erneut an.',
            ]);
        }

        // Der Kontostatus wird auch im zweiten Schritt geprueft. Eine Sperre
        // zwischen Passwort und Code darf die Anmeldung nicht mehr abschliessen,
        // und eine offene Codeeingabe darf die Sperre nicht ueberdauern.
        if (! $this->kontoNutzbar($benutzer)) {
            return $this->kontoNichtNutzbar($request, $benutzer);
        }

        $schluessel = $this->throttleKey($request, $benutzer);

        if (RateLimiter::tooManyAttempts($schluessel, self::VERSUCHE)) {
            throw ValidationException::withMessages([
                'code' => $this->sperrhinweis(RateLimiter::availableIn($schluessel)),
            ]);
        }

        if (! $this->zweiFaktor->verifyCodeOrRecovery($benutzer, $request->code())) {
            RateLimiter::hit($schluessel, self::SPERRE_SEKUNDEN);

            // Verzoegerung gegen automatisierte Versuche.
            usleep(250_000);

            throw ValidationException::withMessages([
                'code' => 'Der Code ist nicht gültig. Bitte geben Sie den aktuell angezeigten Code ein.',
            ]);
        }

        RateLimiter::clear($schluessel);

        return $this->anmelden($request, $benutzer);
    }

    /**
     * Meldet ab und verwirft den offenen zweiten Schritt.
     */
    public function abort(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Die Anmeldung wurde abgebrochen.');
    }

    /**
     * Schliesst die Anmeldung nach gueltigem Faktor ab.
     */
    private function anmelden(Request $request, User $benutzer): RedirectResponse
    {
        $merken = $request->session()->get(TwoFactorAuthentication::SESSION_MERKEN) === true;

        $request->session()->forget([
            TwoFactorAuthentication::SESSION_OFFENER_NUTZER,
            TwoFactorAuthentication::SESSION_MERKEN,
        ]);

        Auth::guard('web')->login($benutzer, $merken);

        // Sitzungskennung nach der Anmeldung neu erzeugen.
        $request->session()->regenerate();

        $benutzer->forceFill(['last_login_at' => now()])->save();

        $this->audit->record(
            action: 'account.logged_in',
            subject: $benutzer,
            actor: $benutzer,
            metadata: ['zweitfaktor' => 'totp'],
        );

        // Mandantenauswahl bei jeder Anmeldung neu setzen.
        $ids = $benutzer->organizationIds();
        $request->session()->put(EnsureOrganizationContext::SESSION_KEY, $ids[0] ?? null);

        return redirect()->intended($this->destination->for($benutzer));
    }

    /**
     * Konto, dessen zweiter Faktor noch offen ist.
     *
     * Beruecksichtigt zwei Faelle: den Regelfall nach der Passworteingabe und
     * eine bereits angemeldete Sitzung, deren Faktor noch nicht nachgewiesen
     * ist. Der zweite Fall entsteht, wenn eine Adminsitzung von
     * App\Http\Middleware\RequireAdminTwoFactor hierher geleitet wird.
     */
    private function offenerNutzer(Request $request): ?User
    {
        $id = $request->session()->get(TwoFactorAuthentication::SESSION_OFFENER_NUTZER);

        if (is_string($id) && $id !== '') {
            /** @var User|null $benutzer */
            $benutzer = User::query()->whereKey($id)->first();

            if ($benutzer instanceof User && $this->zweiFaktor->isConfirmed($benutzer)) {
                return $benutzer;
            }
        }

        $angemeldet = $request->user();

        if ($angemeldet instanceof User && $this->zweiFaktor->isConfirmed($angemeldet)) {
            return $angemeldet;
        }

        return null;
    }

    /**
     * Nur aktive und unbestaetigte Konten duerfen die Anmeldung abschliessen,
     * dieselbe Regel wie in App\Http\Requests\Auth\LoginRequest.
     */
    private function kontoNutzbar(User $benutzer): bool
    {
        $status = $benutzer->getAttribute('status');

        return $status === UserStatus::AKTIV || $status === UserStatus::UNBESTAETIGT;
    }

    /**
     * Verwirft den offenen zweiten Schritt und meldet mit derselben Meldung
     * wie der erste Schritt ab.
     */
    private function kontoNichtNutzbar(Request $request, User $benutzer): RedirectResponse
    {
        $status = $benutzer->getAttribute('status');

        Auth::guard('web')->logout();
        $request->session()->forget([
            TwoFactorAuthentication::SESSION_OFFENER_NUTZER,
            TwoFactorAuthentication::SESSION_MERKEN,
        ]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $meldung = $status === UserStatus::GESPERRT
            ? 'Dieses Konto ist gesperrt. Bitte wenden Sie sich an kontakt@smart-abrechnen.de.'
            : 'Dieses Konto steht nicht zur Verfügung. Bitte wenden Sie sich an kontakt@smart-abrechnen.de.';

        return redirect()->route('login')->withErrors(['email' => $meldung]);
    }

    private function throttleKey(Request $request, User $benutzer): string
    {
        return 'zwei-faktor:'.Str::transliterate(
            ((string) $benutzer->getKey()).'|'.((string) $request->ip())
        );
    }

    private function sperrhinweis(int $sekunden): string
    {
        if ($sekunden >= 60) {
            $minuten = (int) ceil($sekunden / 60);

            return sprintf(
                'Zu viele Versuche. Bitte versuchen Sie es in %d %s erneut.',
                $minuten,
                $minuten === 1 ? 'Minute' : 'Minuten'
            );
        }

        return sprintf(
            'Zu viele Versuche. Bitte versuchen Sie es in %d %s erneut.',
            max(1, $sekunden),
            $sekunden === 1 ? 'Sekunde' : 'Sekunden'
        );
    }
}
