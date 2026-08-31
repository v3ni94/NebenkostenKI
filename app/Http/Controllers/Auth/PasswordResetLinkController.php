<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetLinkRequest;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Anforderung eines Links zum Zuruecksetzen des Passworts.
 *
 * KEINE KONTOERKENNUNG: Die Antwort ist immer dieselbe, unabhaengig davon, ob
 * zu der Adresse ein Konto besteht, ob der Versand gedrosselt wurde oder ob der
 * Link tatsaechlich versendet wurde. Andernfalls waere das Formular ein
 * bequemes Werkzeug, um gueltige Adressen zu ermitteln.
 *
 * Der Versand laeuft ueber den Laravel-Passwortbroker, weil dieser die
 * Drosselung, die Tokenerzeugung und das einmalige Verfallen bereits richtig
 * umsetzt. Versendet wird jedoch die eigene deutsche Benachrichtigung.
 */
class PasswordResetLinkController extends Controller
{
    private const ALLGEMEINE_ANTWORT = 'Falls zu dieser E-Mail-Adresse ein Konto besteht, haben wir einen Link '
        .'zum Zurücksetzen des Passworts gesendet. Bitte prüfen Sie Ihr Postfach.';

    public function create(): View
    {
        return view('auth.passwort-vergessen');
    }

    public function store(PasswordResetLinkRequest $request): RedirectResponse
    {
        Password::broker()->sendResetLink(
            ['email' => Str::lower((string) $request->string('email'))],
            function (CanResetPassword $user, string $token): void {
                if ($user instanceof User) {
                    $user->notify(new ResetPasswordLink($token));
                }
            }
        );

        return back()->with('status', self::ALLGEMEINE_ANTWORT);
    }
}
