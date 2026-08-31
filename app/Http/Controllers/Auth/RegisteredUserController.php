<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Account\EmailVerification;
use App\Application\Account\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Registrierung eines neuen Kundenkontos.
 *
 * Mit der Registrierung entsteht in derselben Transaktion die persoenliche
 * Organisation des Nutzers samt Mitgliedschaft als Inhaber. Ohne Organisation
 * gaebe es keinen Mandanten, auf den Kundendaten gescopet werden koennten.
 *
 * Anschliessend wird der Nutzer angemeldet, die Sitzung neu erzeugt und der
 * Bestaetigungslink versendet. Konto und Entwuerfe sind ohne bestaetigte
 * Adresse nutzbar, Zahlung und finaler Download nicht (Masterprompt 8.1).
 */
class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly RegisterUser $registerUser,
        private readonly EmailVerification $verification,
    ) {}

    public function create(): View
    {
        return view('auth.registrieren');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $ergebnis = $this->registerUser->handle(
            name: (string) $request->string('name'),
            email: (string) $request->string('email'),
            password: (string) $request->string('password'),
        );

        Auth::login($ergebnis['user']);
        $request->session()->regenerate();
        $request->session()->put(
            EnsureOrganizationContext::SESSION_KEY,
            $ergebnis['organization']->getKey()
        );

        $this->verification->send($ergebnis['user']);

        return redirect()
            ->route('verification.notice')
            ->with('status', 'Ihr Konto ist angelegt. Wir haben Ihnen eine E-Mail zur Bestätigung gesendet.');
    }
}
