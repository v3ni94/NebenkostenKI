<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Account\AuditRecorder;
use App\Application\Account\LoginDestination;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Anmeldung und Abmeldung.
 *
 * SITZUNGSSICHERHEIT
 *
 *  - Nach erfolgreicher Anmeldung wird die Sitzungskennung neu erzeugt
 *    (Session Fixation).
 *  - Bei der Abmeldung wird die Sitzung ungueltig gemacht und das
 *    CSRF-Token neu erzeugt.
 *  - Die Cookieeigenschaften HttpOnly, Secure, SameSite und die
 *    Sitzungsverschluesselung kommen aus config/session.php und der .env
 *    (SESSION_ENCRYPT, SESSION_SECURE_COOKIE, SESSION_SAME_SITE).
 *
 * Ein gesperrtes oder zur Loeschung vorgemerktes Konto wird nach der
 * Passwortpruefung wieder abgemeldet. Die Meldung bleibt bewusst allgemein.
 *
 * ZWEITER FAKTOR
 *
 * Ist fuer das Konto ein bestaetigter Zweitfaktor hinterlegt, endet die
 * Passwortpruefung ohne Anmeldung, und der Nutzer wird zur Codeeingabe geleitet.
 * Siehe App\Http\Requests\Auth\LoginRequest und
 * App\Http\Controllers\Auth\TwoFactorChallengeController.
 */
class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly LoginDestination $destination,
    ) {}

    public function create(): View
    {
        return view('auth.anmelden');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Sitzungskennung nach der Anmeldung neu erzeugen. Der Aufruf erhaelt
        // die Sitzungsdaten und damit auch den Hinweis auf den offenen zweiten
        // Faktor.
        $request->session()->regenerate();

        if ($request->zweitfaktorErforderlich()) {
            // Die Passwortpruefung war erfolgreich, der Nutzer ist aber bewusst
            // nicht angemeldet. Erst der gueltige Code schliesst die Anmeldung
            // ab (Masterprompt 8.1).
            return redirect()->route('two-factor.challenge');
        }

        $user = $request->user();

        if ($user instanceof User) {
            $user->forceFill(['last_login_at' => now()])->save();

            $this->audit->record(
                action: 'account.logged_in',
                subject: $user,
                actor: $user,
            );

            // Mandantenauswahl bei jeder Anmeldung neu setzen, damit keine
            // Auswahl aus einer fremden Sitzung uebernommen wird.
            $ids = $user->organizationIds();
            $request->session()->put(
                EnsureOrganizationContext::SESSION_KEY,
                $ids[0] ?? null
            );
        }

        // Interne Kennungen ohne Mandant werden direkt zur Einrichtung des
        // Zweitfaktors beziehungsweise in den Adminbereich gefuehrt.
        return redirect()->intended(
            $user instanceof User ? $this->destination->for($user) : route('portal.dashboard'),
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->audit->record(
                action: 'account.logged_out',
                subject: $user,
                actor: $user,
            );
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('site.home')
            ->with('status', 'Sie sind abgemeldet.');
    }
}
