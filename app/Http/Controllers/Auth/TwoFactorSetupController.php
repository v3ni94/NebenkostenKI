<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Account\TwoFactorAuthentication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorCodeRequest;
use App\Http\Requests\Auth\TwoFactorDisableRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einrichtung und Abschaltung des Zweitfaktors im angemeldeten Zustand.
 *
 * ABLAUF
 *
 *  1. Der Nutzer startet die Einrichtung. Es entsteht ein Geheimnis, das
 *     verschluesselt gespeichert wird. Der Faktor ist noch nicht aktiv.
 *  2. Die Seite zeigt die otpauth-URI und denselben Schluessel in Vierergruppen
 *     zum Abtippen. Es wird bewusst KEIN QR-Code-Bild erzeugt, weil dafuer ein
 *     zusaetzliches Paket noetig waere. Der Weg ist im Text erklaert.
 *  3. Der Nutzer bestaetigt mit einem gueltigen Code. Erst dann ist der Faktor
 *     aktiv, und die acht Wiederherstellungscodes werden genau einmal angezeigt.
 *  4. Die Abschaltung verlangt das aktuelle Passwort UND einen gueltigen Faktor.
 *
 * Die Seite ist auch fuer eine Adminkennung der Einstieg: Die Middleware
 * App\Http\Middleware\RequireAdminTwoFactor leitet eine Adminkennung ohne
 * aktiven Zweitfaktor hierher, statt den internen Bereich zu sperren.
 */
class TwoFactorSetupController extends Controller
{
    public function __construct(private readonly TwoFactorAuthentication $zweiFaktor) {}

    public function show(Request $request): View
    {
        $benutzer = $this->benutzer($request);

        /** @var list<string> $codes */
        $codes = is_array($request->session()->get(TwoFactorAuthentication::SESSION_CODES))
            ? array_values(array_filter(
                (array) $request->session()->get(TwoFactorAuthentication::SESSION_CODES),
                static fn (mixed $wert): bool => is_string($wert),
            ))
            : [];

        return view('auth.zwei-faktor-einrichten', [
            'aktiv' => $this->zweiFaktor->isConfirmed($benutzer),
            'begonnen' => $this->zweiFaktor->isPending($benutzer),
            'otpauthUri' => $this->zweiFaktor->isPending($benutzer)
                ? $this->zweiFaktor->otpauthUri($benutzer)
                : null,
            'schluessel' => $this->zweiFaktor->isPending($benutzer)
                ? $this->zweiFaktor->formattedSecret($benutzer)
                : null,
            'wiederherstellungscodes' => $codes,
            'verbleibendeCodes' => $this->zweiFaktor->remainingRecoveryCodes($benutzer),
        ]);
    }

    /**
     * Erzeugt ein neues Geheimnis und beginnt damit die Einrichtung.
     */
    public function start(Request $request): RedirectResponse
    {
        $benutzer = $this->benutzer($request);

        if ($this->zweiFaktor->isConfirmed($benutzer)) {
            return redirect()->route('two-factor.setup')
                ->with('status', 'Der Zweitfaktor ist für dieses Konto bereits aktiv.');
        }

        $this->zweiFaktor->beginSetup($benutzer);

        return redirect()->route('two-factor.setup')
            ->with('status', 'Bitte tragen Sie den Schlüssel in Ihre Authenticator-App ein und bestätigen Sie '
                .'anschließend mit einem Code.');
    }

    /**
     * Bestaetigt die Einrichtung mit einem gueltigen Code.
     */
    public function confirm(TwoFactorCodeRequest $request): RedirectResponse
    {
        $benutzer = $this->benutzer($request);

        $codes = $this->zweiFaktor->confirm($benutzer, $request->code());

        if ($codes === null) {
            return redirect()->route('two-factor.setup')
                ->withErrors(['code' => 'Der Code ist nicht gültig. Bitte prüfen Sie die Uhrzeit Ihres Geräts und '
                    .'geben Sie den aktuell angezeigten Code ein.']);
        }

        // Die Sitzung hat den Faktor damit gerade nachgewiesen.
        $request->session()->forget(TwoFactorAuthentication::SESSION_OFFENER_NUTZER);

        return redirect()->route('two-factor.setup')
            ->with(TwoFactorAuthentication::SESSION_CODES, $codes)
            ->with('status', 'Der Zweitfaktor ist aktiv.');
    }

    /**
     * Schaltet den Zweitfaktor ab. Passwort und gueltiger Faktor sind Pflicht.
     */
    public function disable(TwoFactorDisableRequest $request): RedirectResponse
    {
        $benutzer = $this->benutzer($request);

        if (! $this->zweiFaktor->isConfirmed($benutzer)) {
            return redirect()->route('two-factor.setup')
                ->with('status', 'Für dieses Konto ist kein Zweitfaktor aktiv.');
        }

        if (! $this->zweiFaktor->verifyCodeOrRecovery($benutzer, $request->code())) {
            return redirect()->route('two-factor.setup')
                ->withErrors(['code' => 'Der Code ist nicht gültig. Die Abschaltung wurde nicht durchgeführt.']);
        }

        $this->zweiFaktor->disable($benutzer);

        return redirect()->route('two-factor.setup')
            ->with('status', 'Der Zweitfaktor ist abgeschaltet. Sie können ihn jederzeit wieder einrichten.');
    }

    private function benutzer(Request $request): User
    {
        $benutzer = $request->user();

        if (! $benutzer instanceof User) {
            abort(403);
        }

        return $benutzer;
    }
}
