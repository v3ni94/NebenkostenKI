<?php

declare(strict_types=1);

namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Wartungsaufruf per URL fuer Hosting ohne Shellzugang.
 *
 * Bei IONOS Webhosting lassen sich Cronjobs nur als Aufruf einer Webadresse
 * anlegen. Diese Route fuehrt die dafuer noetigen Artisan-Befehle aus und gibt
 * ihre Ausgabe als Text zurueck. Sie ist ausschliesslich mit dem in CRON_TOKEN
 * hinterlegten Schluessel erreichbar; ohne konfigurierten Schluessel existiert
 * sie nach aussen nicht (404).
 *
 * Erlaubte Aufgaben:
 *   schedule      schedule:run, jede Minute aufzurufen
 *   install       smartabrechnen:install, idempotent, einmal nach jedem Release
 *   check-config  smartabrechnen:check-config
 *   admin         smartabrechnen:admin:create mit email und name als Parameter;
 *                 das Einmalpasswort erscheint genau einmal in der Antwort
 *
 * Es werden keine weiteren Befehle angenommen. Jeder Aufruf wird protokolliert.
 */
final class CronController extends Controller
{
    private const array AUFGABEN = [
        'schedule' => 'schedule:run',
        'install' => 'smartabrechnen:install',
        'check-config' => 'smartabrechnen:check-config',
        'admin' => 'smartabrechnen:admin:create',
    ];

    public const int TOKEN_MINDESTLAENGE = 32;

    public function __invoke(Request $request, string $aufgabe): Response
    {
        $token = (string) config('smartabrechnen.cron_token', '');

        if (mb_strlen($token) < self::TOKEN_MINDESTLAENGE) {
            throw new NotFoundHttpException;
        }

        $geliefert = (string) $request->query('token', '');

        if ($geliefert === '' || ! hash_equals($token, $geliefert)) {
            Log::warning('Wartungsaufruf mit ungültigem Schlüssel abgewiesen.', ['aufgabe' => $aufgabe, 'ip' => $request->ip()]);

            return response('Zugriff verweigert.', 403)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        if (! array_key_exists($aufgabe, self::AUFGABEN)) {
            return response('Unbekannte Aufgabe. Erlaubt sind: '.implode(', ', array_keys(self::AUFGABEN)).'.', 404)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $parameter = ['--no-interaction' => true];

        if ($aufgabe === 'admin') {
            $eingaben = $request->validate([
                'email' => ['required', 'email:rfc', 'max:255'],
                'name' => ['nullable', 'string', 'max:120', Rule::notIn([''])],
            ]);
            $parameter['--email'] = $eingaben['email'];
            if (isset($eingaben['name'])) {
                $parameter['--name'] = $eingaben['name'];
            }
        }

        // Installation und Konfigurationspruefung koennen laenger dauern als
        // ein normaler Seitenaufruf. Die Ausfuehrung laeuft weiter, auch wenn
        // der Aufrufer die Verbindung beendet.
        ignore_user_abort(true);
        set_time_limit(0);

        Log::info('Wartungsaufruf gestartet.', ['aufgabe' => $aufgabe, 'ip' => $request->ip()]);

        $exit = Artisan::call(self::AUFGABEN[$aufgabe], $parameter);
        $ausgabe = trim(Artisan::output());

        Log::info('Wartungsaufruf beendet.', ['aufgabe' => $aufgabe, 'exit' => $exit]);

        $text = sprintf("Aufgabe: %s\nErgebnis: %s (Code %d)\n\n%s\n", $aufgabe, $exit === 0 ? 'erfolgreich' : 'fehlgeschlagen', $exit, $ausgabe);

        return response($text, $exit === 0 ? 200 : 500)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
