<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Voraussetzungen einer Adminsitzung (Masterprompt 20, ARCHITECTURE.md T10).
 *
 * WARUM DIESE MIDDLEWARE KONSERVATIV IST
 *
 * Der Zweitfaktor selbst ist noch nicht umgesetzt; im Auth-Paket ist er als
 * TODO vermerkt, und bootstrap/app.php haelt fest, dass TOTP-2FA fuer
 * Adminrollen verpflichtend ist. Die Middleware prueft deshalb das
 * Bestaetigungsmerkmal am Nutzer (users.two_factor_confirmed_at). Fehlt es,
 * gilt:
 *
 *   Produktion            Der Adminbereich ist gesperrt (403) mit dem klaren
 *                         Hinweis, dass der Zweitfaktor vor dem Livegang
 *                         einzurichten ist.
 *   local und testing     Der Bereich bleibt nutzbar, damit die Entwicklung
 *                         und die Testsuite arbeiten koennen.
 *
 * Zusaetzlich wird der Kontostatus geprueft. Eine gesperrte oder zur Loeschung
 * vorgemerkte Kennung erhaelt in KEINER Umgebung Zugang zum Adminbereich, auch
 * dann nicht, wenn die Anmeldung im Kundenbereich noch moeglich ist.
 *
 * REGISTRIERUNG: Die Middleware wird nicht in bootstrap/app.php eingetragen,
 * sondern in routes/admin.php an die Adminroutengruppe gehaengt. Damit bleibt
 * die Wirkung auf den Adminbereich begrenzt und ist an genau einer Stelle
 * ablesbar.
 */
class RequireAdminTwoFactor
{
    /**
     * Umgebungen, in denen der fehlende Zweitfaktor den Zugang noch nicht
     * sperrt. Identisch zur Sonderregel des Testproviders in
     * App\Services\Ai\ProviderReleaseGate.
     *
     * @var list<string>
     */
    public const array NICHT_PRODUKTIVE_UMGEBUNGEN = ['local', 'testing'];

    public const string MELDUNG_ZWEITFAKTOR = 'Für den internen Bereich ist eine Zwei-Faktor-Authentifizierung '
        .'verpflichtend. Sie ist für dieses Konto noch nicht eingerichtet. Der Zweitfaktor ist vor dem Livegang '
        .'einzurichten, bis dahin bleibt der interne Bereich in der Produktionsumgebung gesperrt.';

    public const string MELDUNG_GESPERRT = 'Diese Kennung ist gesperrt. Der interne Bereich ist damit nicht '
        .'zugänglich.';

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

        if ($user->getAttribute('two_factor_confirmed_at') !== null) {
            return $next($request);
        }

        if ($this->isNonProductionEnvironment()) {
            // Der Hinweis bleibt sichtbar, damit der offene Punkt nicht in
            // Vergessenheit geraet. Gesperrt wird hier bewusst nicht.
            $request->attributes->set('admin_zweitfaktor_fehlt', true);

            return $next($request);
        }

        abort(403, self::MELDUNG_ZWEITFAKTOR);
    }

    private function isNonProductionEnvironment(): bool
    {
        return in_array(
            strtolower((string) app()->environment()),
            self::NICHT_PRODUKTIVE_UMGEBUNGEN,
            true,
        );
    }
}
