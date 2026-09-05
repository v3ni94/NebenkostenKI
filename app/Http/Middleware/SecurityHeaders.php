<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sicherheitsheader fuer alle Webantworten.
 *
 * Vorgabe des Masterprompts, Abschnitt 19: HTTPS, HSTS, CSP, Referrer-Policy
 * und weitere Sicherheitsheader.
 *
 * CONTENT SECURITY POLICY
 *
 * Die Richtlinie ist auf die tatsaechlich verwendeten Bausteine zugeschnitten:
 *
 *  - Vite liefert CSS und JavaScript als eigene Dateien unter /build aus. Sie
 *    werden von 'self' abgedeckt, ein Nonce ist dafuer nicht erforderlich.
 *  - Beide Layouts enthalten genau einen kurzen Inline-Baustein zur
 *    Fortschrittserkennung von JavaScript. Er wird ueber seinen SHA-256-Hash
 *    freigegeben, nicht ueber 'unsafe-inline'. Der Hash bezieht sich auf den
 *    exakten Skriptinhalt:
 *
 *        document.documentElement.classList.add('js');
 *
 *    Aendert sich dieser Inhalt, ist der Hash neu zu berechnen mit:
 *
 *        php -r 'echo base64_encode(hash("sha256", $inhalt, true));'
 *
 *  - Alpine.js wertet Ausdruecke aus Attributen zur Laufzeit mit einer
 *    Funktionskonstruktion aus. Der Standardbuild benoetigt dafuer
 *    'unsafe-eval'. OFFENER PUNKT: Mit dem CSP-Build von Alpine
 *    (@alpinejs/csp) entfaellt 'unsafe-eval'. Der Wechsel beruehrt
 *    package.json und resources/js/app.js und gehoert damit in ein anderes
 *    Arbeitspaket.
 *  - style-src erlaubt 'unsafe-inline', weil beide Layouts einen kurzen
 *    Inline-Stilblock fuer die Fortschrittserkennung enthalten. Ein
 *    Inline-Stil kann kein Skript ausfuehren, das Restrisiko ist deutlich
 *    geringer als bei Skripten.
 *  - Ausserhalb der Produktion wird zusaetzlich der Vite-Entwicklungsserver
 *    freigegeben, damit der Hot-Reload funktioniert.
 *  - form-action erlaubt neben 'self' den Ursprung der gehosteten
 *    Zahlungsseite, siehe ZAHLUNGSANBIETER_FORM_ACTION.
 *
 * Die Middleware ist global registriert (bootstrap/app.php), nicht nur in der
 * Gruppe "web". Nur so tragen auch Antworten, die vor dem Erreichen einer
 * Route entstehen (404 fuer unbekannte Pfade, 405, die HTTPS-Umleitung), die
 * Sicherheitsheader.
 *
 * HSTS wird ausschliesslich ueber HTTPS und ausschliesslich in der Produktion
 * gesetzt. Ein HSTS-Header auf einer Entwicklungsumgebung sperrt den Browser
 * dauerhaft auf HTTPS und ist nur schwer zurueckzunehmen.
 */
class SecurityHeaders
{
    /**
     * SHA-256 des Inline-Bausteins zur JavaScript-Erkennung, Base64-kodiert.
     */
    private const INLINE_SKRIPT_HASH = 'sha256-/x7W7R75k8Roq0WaVRQX9blP4OufE5xbAdzklGxsgpw=';

    /**
     * Ursprung der gehosteten Zahlungsseite des Zahlungsanbieters.
     *
     * Der Bezahlschritt ist ein gewoehnliches HTML-Formular, dessen POST mit
     * einer Weiterleitung auf die Checkout-Session des Anbieters beantwortet
     * wird. Chromium- und WebKit-Browser pruefen form-action auch gegen das
     * Ziel dieser Weiterleitung. Ohne diesen Eintrag bricht der Browser die
     * Navigation zur Zahlungsseite ab und eine Zahlung ist nicht moeglich.
     * Wird spaeter eine eigene Checkout-Domain beim Anbieter eingerichtet,
     * ist sie hier zu ergaenzen.
     */
    public const ZAHLUNGSANBIETER_FORM_ACTION = 'https://checkout.stripe.com';

    /**
     * Ursprung des Vite-Entwicklungsservers, nur ausserhalb der Produktion.
     *
     * @var list<string>
     */
    private const VITE_ENTWICKLUNG = [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', $this->permissionsPolicy());
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Kundendaten duerfen nie in einem geteilten Cache landen.
        if ($request->user() !== null) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        if ($this->hstsErlaubt($request)) {
            $headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $produktion = app()->environment('production');

        $skript = ["'self'", "'".self::INLINE_SKRIPT_HASH."'", "'unsafe-eval'"];
        $verbindung = ["'self'"];

        if (! $produktion) {
            $skript = array_merge($skript, self::VITE_ENTWICKLUNG);
            $verbindung = array_merge($verbindung, self::VITE_ENTWICKLUNG, [
                'ws://localhost:5173',
                'ws://127.0.0.1:5173',
            ]);
        }

        $direktiven = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self' ".self::ZAHLUNGSANBIETER_FORM_ACTION,
            "frame-ancestors 'none'",
            "object-src 'none'",
            'script-src '.implode(' ', $skript),
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            'connect-src '.implode(' ', $verbindung),
            "manifest-src 'self'",
            "media-src 'none'",
            "worker-src 'self'",
            "frame-src 'self'",
        ];

        if ($produktion) {
            $direktiven[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $direktiven);
    }

    /**
     * Alle Browserfunktionen, die die Anwendung nicht benoetigt, werden
     * ausdruecklich abgeschaltet.
     */
    private function permissionsPolicy(): string
    {
        return implode(', ', [
            'accelerometer=()',
            'autoplay=()',
            'camera=()',
            'display-capture=()',
            'encrypted-media=()',
            'fullscreen=(self)',
            'geolocation=()',
            'gyroscope=()',
            'magnetometer=()',
            'microphone=()',
            'midi=()',
            'payment=()',
            'usb=()',
            'xr-spatial-tracking=()',
        ]);
    }

    private function hstsErlaubt(Request $request): bool
    {
        return app()->environment('production') && $request->isSecure();
    }
}
