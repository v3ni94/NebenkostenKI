<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Account\TwoFactorAuthentication;
use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Voraussetzungen einer Adminsitzung (Masterprompt 20, ARCHITECTURE.md T10).
 *
 * Fuer Nutzer mit einer Adminrolle ist der Zweitfaktor verpflichtend. Die
 * Middleware prueft drei Voraussetzungen:
 *
 *   1. Kontostatus     Eine gesperrte oder zur Loeschung vorgemerkte Kennung
 *                      erhaelt in KEINER Umgebung Zugang zum internen Bereich,
 *                      auch dann nicht, wenn die Anmeldung im Kundenbereich noch
 *                      moeglich ist.
 *   2. Zweitfaktor     Fehlt der bestaetigte Faktor, wird die Kennung auf die
 *                      Einrichtung geleitet. Der Bereich wird NICHT pauschal
 *                      gesperrt: Eine Sperre haette den Betreiber aus seinem
 *                      eigenen Adminbereich ausgeschlossen, ohne ihm einen Weg
 *                      zur Einrichtung zu lassen. Nach der Einrichtung ist der
 *                      Bereich auch in der Produktionsumgebung nutzbar.
 *   3. Nachweis in der
 *      Sitzung         Ist der Faktor aktiv, die laufende Sitzung hat ihn aber
 *                      nicht nachgewiesen, wird zur Codeeingabe geleitet.
 *
 * Zum dritten Punkt: Nach der Passwortpruefung wird ein Konto mit aktivem
 * Zweitfaktor bewusst nicht angemeldet, siehe
 * App\Http\Requests\Auth\LoginRequest. Eine Sitzung ohne Nachweis ist also im
 * Regelfall gar nicht angemeldet und erreicht den Bereich nicht. Der hier
 * gepruefte Sitzungsschluessel deckt den verbleibenden Fall ab, dass eine
 * Sitzung den ersten Schritt bereits hinter sich hat und der zweite noch
 * offensteht.
 *
 * REGISTRIERUNG: Die Middleware wird nicht in bootstrap/app.php eingetragen,
 * sondern in routes/admin.php an die Adminroutengruppe gehaengt. Damit bleibt
 * die Wirkung auf den Adminbereich begrenzt und ist an genau einer Stelle
 * ablesbar.
 */
class RequireAdminTwoFactor
{
    public const string MELDUNG_ZWEITFAKTOR = 'Für den internen Bereich ist eine Zwei-Faktor-Authentifizierung '
        .'verpflichtend. Bitte richten Sie sie jetzt ein, danach steht der interne Bereich zur Verfügung.';

    public const string MELDUNG_CODE = 'Bitte weisen Sie für diese Sitzung den zweiten Faktor nach.';

    public const string MELDUNG_GESPERRT = 'Diese Kennung ist gesperrt. Der interne Bereich ist damit nicht '
        .'zugänglich.';

    public function __construct(private readonly TwoFactorAuthentication $zweiFaktor) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403, self::MELDUNG_ZWEITFAKTOR);
        }

        $status = $user->getAttribute('status');

        if ($status === UserStatus::GESPERRT || $status === UserStatus::GELOESCHT) {
            abort(403, self::MELDUNG_GESPERRT);
        }

        if (! $this->zweiFaktor->isConfirmed($user)) {
            return redirect()
                ->route('two-factor.setup')
                ->with('status', self::MELDUNG_ZWEITFAKTOR);
        }

        if ($this->zweitfaktorNochOffen($request)) {
            return redirect()
                ->route('two-factor.challenge')
                ->with('status', self::MELDUNG_CODE);
        }

        return $next($request);
    }

    /**
     * Hat die Sitzung den zweiten Faktor noch nicht nachgewiesen?
     */
    private function zweitfaktorNochOffen(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $offen = $request->session()->get(TwoFactorAuthentication::SESSION_OFFENER_NUTZER);

        return is_string($offen) && $offen !== '';
    }
}
