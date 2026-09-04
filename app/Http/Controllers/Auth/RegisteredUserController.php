<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Account\AuditRecorder;
use App\Application\Account\EmailVerification;
use App\Application\Account\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Notifications\KontoBereitsVorhanden;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

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
 *
 * KEINE KONTOERKENNUNG (Masterprompt 8.1, 19)
 *
 * Ist die Adresse bereits registriert, antwortet die Anwendung mit derselben
 * Weiterleitung und derselben Statusmeldung wie bei einer erfolgreichen
 * Registrierung. Es entsteht KEIN zweites Konto, es wird niemand angemeldet, und
 * die Antwort verraet nicht, dass ein Konto besteht. Stattdessen geht eine
 * sachliche Hinweismail an die bestehende Adresse, mit den Wegen Anmeldung und
 * Passwort zuruecksetzen.
 *
 * ANTWORTZEIT
 *
 * Der teure Teil einer Registrierung ist das Passworthashing mit Argon2id. Im
 * Hinweiszweig wird deshalb bewusst derselbe Hash berechnet und verworfen. Ohne
 * diesen Schritt waere die Antwortzeit deutlich kuerzer, und genau daran waere
 * ein bestehendes Konto erkennbar.
 *
 * Eine zur Loeschung vorgemerkte Kennung erhaelt keine Hinweismail. Sie belegt
 * die Adresse weiterhin, soll aber keine Nachrichten mehr bekommen.
 */
class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly RegisterUser $registerUser,
        private readonly EmailVerification $verification,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(): View
    {
        return view('auth.registrieren');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $email = Str::lower(trim((string) $request->string('email')));
        $passwort = (string) $request->string('password');

        $vorhanden = $this->vorhandenesKonto($email);

        if ($vorhanden instanceof User) {
            $versendet = $this->hinweisStattZweitkonto($vorhanden, $passwort);

            return $this->bestaetigung($versendet);
        }

        $ergebnis = $this->registerUser->handle(
            name: (string) $request->string('name'),
            email: $email,
            password: $passwort,
        );

        Auth::login($ergebnis['user']);
        $request->session()->regenerate();
        $request->session()->put(
            EnsureOrganizationContext::SESSION_KEY,
            $ergebnis['organization']->getKey()
        );

        // Ein Zustellfehler laesst das Konto vollstaendig angelegt und den
        // Nutzer angemeldet; er erhaelt eine neutrale Meldung und kann den
        // Link auf der Hinweisseite erneut anfordern.
        $versendet = $this->verification->send($ergebnis['user']);

        return $this->bestaetigung($versendet);
    }

    /**
     * Antwort, die in beiden Faellen identisch ist. Nur ein Zustellfehler
     * aendert die Meldung, und zwar in beiden Zweigen gleich.
     */
    private function bestaetigung(bool $versendet): RedirectResponse
    {
        return redirect()
            ->route('verification.notice')
            ->with('status', $versendet
                ? 'Ihr Konto ist angelegt. Wir haben Ihnen eine E-Mail zur Bestätigung gesendet.'
                : 'Ihr Konto ist angelegt. '.EmailVerification::MELDUNG_VERSAND_FEHLGESCHLAGEN);
    }

    /**
     * Hinweismail an die bestehende Adresse, ohne zweites Konto.
     *
     * @return bool false nur bei einem Zustellfehler
     */
    private function hinweisStattZweitkonto(User $vorhanden, string $passwort): bool
    {
        // Gleicher Rechenaufwand wie bei einer echten Registrierung, damit die
        // Antwortzeit sich nicht auffaellig unterscheidet.
        Hash::make($passwort);

        $geloescht = $vorhanden->getAttribute('deleted_at') !== null;
        $versendet = true;

        if (! $geloescht) {
            try {
                $vorhanden->notify(new KontoBereitsVorhanden);
            } catch (Throwable $fehler) {
                $versendet = false;

                Log::warning('Hinweismail zu bestehendem Konto konnte nicht versendet werden.', [
                    'user_id' => $vorhanden->getKey(),
                    'fehler' => $fehler::class,
                ]);
            }
        }

        $this->audit->record(
            action: 'account.register_existing_email',
            subject: $vorhanden,
            metadata: ['hinweismail_versendet' => ! $geloescht && $versendet],
        );

        return $versendet;
    }

    /**
     * Bestehendes Konto zu einer Adresse, auch ein zur Loeschung vorgemerktes.
     *
     * Ein soft deleted Konto belegt die Adresse weiterhin, weil der eindeutige
     * Index auf users.email bestehen bleibt. Es muss deshalb mitgeprueft werden.
     */
    private function vorhandenesKonto(string $email): ?User
    {
        /** @var User|null $konto */
        $konto = User::withTrashed()->where('email', $email)->first();

        return $konto;
    }
}
