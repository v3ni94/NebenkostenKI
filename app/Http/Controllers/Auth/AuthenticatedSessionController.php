<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Account\AuditRecorder;
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
 */
class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function create(): View
    {
        return view('auth.anmelden');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Sitzungskennung nach der Anmeldung neu erzeugen.
        $request->session()->regenerate();

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

        return redirect()->intended(route('portal.dashboard'));
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
