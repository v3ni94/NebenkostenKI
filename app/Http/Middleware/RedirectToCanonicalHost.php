<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leitet die www-Variante dauerhaft auf die kanonische Domain um.
 *
 * Masterprompt 3.1: Kanonische Produktiv-URL ist APP_URL, eine eingerichtete
 * Adresse www.<domain> leitet dauerhaft (301) darauf um. Pfad und Query bleiben
 * erhalten.
 *
 * Erste Verteidigungslinie ist die Regel in public/.htaccess, die Apache ohne
 * PHP-Aufruf beantwortet. Diese Middleware ist der Rueckfall, falls .htaccess
 * nicht greift, etwa bei einem abweichenden Webserver.
 *
 * Es wird ausschliesslich die www-Variante der kanonischen Domain umgeleitet.
 * Andere Hostnamen (Staging, lokale Entwicklung, IP-Aufruf) bleiben
 * unberuehrt, damit eine Umgebung mit eigener APP_URL nicht auf die Produktion
 * zeigt. Die kanonische Domain wird aus APP_URL abgeleitet, nichts ist hart
 * codiert.
 */
class RedirectToCanonicalHost
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $canonical = self::canonicalHost();

        if ($canonical === null) {
            return $next($request);
        }

        $host = strtolower($request->getHost());

        if ($host !== 'www.'.$canonical) {
            return $next($request);
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            // Ein Redirect wuerde die Nutzlast verwerfen. Der Client erhaelt
            // einen verstaendlichen Hinweis statt einer stillen Umleitung.
            abort(403, 'Bitte verwenden Sie die Adresse '.self::canonicalScheme().'://'.$canonical.'.');
        }

        return redirect()->to(
            self::canonicalScheme().'://'.$canonical.$request->getRequestUri(),
            301,
        );
    }

    /**
     * Hostname aus APP_URL, kleingeschrieben, ohne Port.
     */
    public static function canonicalHost(): ?string
    {
        $url = config('app.url');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        // Ist APP_URL selbst eine www-Adresse, gibt es keine Variante, die
        // umgeleitet werden muesste.
        if (str_starts_with($host, 'www.')) {
            return null;
        }

        return $host;
    }

    private static function canonicalScheme(): string
    {
        $url = config('app.url');
        $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;

        return $scheme === 'http' ? 'http' : 'https';
    }
}
