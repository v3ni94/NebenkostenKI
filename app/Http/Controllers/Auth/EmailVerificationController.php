<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Account\EmailVerification;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Hinweisseite, Versand und Bestaetigung der E-Mail-Verifizierung.
 *
 * App\Models\User implementiert MustVerifyEmail nicht, deshalb bildet dieser
 * Controller den Laravel-Standardablauf mit einer eigenen, signierten Route
 * nach. Der Link ist kurzlebig und enthaelt zusaetzlich den SHA-1 der Adresse,
 * damit er nach einer Adressaenderung wertlos ist.
 *
 * Die Bestaetigungsroute traegt die Middleware signed. Eine manipulierte oder
 * abgelaufene URL fuehrt zu 403, ohne dass eine Information ueber das Konto
 * preisgegeben wird.
 */
class EmailVerificationController extends Controller
{
    /**
     * Zulaessige Anforderungen eines neuen Links je Nutzer und Stunde.
     */
    private const VERSAND_LIMIT = 5;

    public function __construct(private readonly EmailVerification $verification) {}

    /**
     * Hinweisseite nach der Registrierung.
     */
    public function notice(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $this->verification->isVerified($user)) {
            return redirect()->route('portal.dashboard');
        }

        return view('auth.verifizierung');
    }

    /**
     * Erneuter Versand des Bestaetigungslinks.
     */
    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        if ($this->verification->isVerified($user)) {
            return redirect()->route('portal.dashboard');
        }

        $schluessel = 'verifizierung:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($schluessel, self::VERSAND_LIMIT)) {
            $minuten = max(1, (int) ceil(RateLimiter::availableIn($schluessel) / 60));

            return back()->with('status', sprintf(
                'Es wurden bereits mehrere Bestätigungslinks versendet. Bitte prüfen Sie Ihr Postfach '
                .'und versuchen Sie es in %d %s erneut.',
                $minuten,
                $minuten === 1 ? 'Minute' : 'Minuten'
            ));
        }

        RateLimiter::hit($schluessel, 3600);

        $this->verification->send($user);

        return back()->with('status', 'Wir haben Ihnen erneut eine E-Mail zur Bestätigung gesendet.');
    }

    /**
     * Bestaetigung ueber den signierten Link.
     */
    public function verify(Request $request, string $user, string $hash): RedirectResponse
    {
        $konto = User::query()->find($user);

        if (! $konto instanceof User) {
            // Keine Auskunft darueber, ob es das Konto gibt.
            abort(403, 'Der Bestätigungslink ist nicht mehr gültig. Bitte fordern Sie einen neuen Link an.');
        }

        if (! $this->verification->markVerified($konto, $hash)) {
            abort(403, 'Der Bestätigungslink ist nicht mehr gültig. Bitte fordern Sie einen neuen Link an.');
        }

        $angemeldet = $request->user();

        if ($angemeldet instanceof User && $angemeldet->getKey() === $konto->getKey()) {
            return redirect()
                ->route('portal.dashboard')
                ->with('status', 'Ihre E-Mail-Adresse ist bestätigt.');
        }

        return redirect()
            ->route('login')
            ->with('status', 'Ihre E-Mail-Adresse ist bestätigt. Bitte melden Sie sich an.');
    }
}
