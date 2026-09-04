<?php

declare(strict_types=1);

use App\Application\Account\OrganizationContext;
use App\Application\BillingRun\IllegalStatusTransitionException;
use App\Application\Install\TrustedProxyConfiguration;
use App\Enums\AdminRole;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\RedirectToCanonicalHost;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Gate;

/*
|------------------------------------------------------------------------------
| Anwendungsbootstrap
|------------------------------------------------------------------------------
|
| Bereichstrennung nach ARCHITECTURE.md, ADR-002:
|
|   /            oeffentliches Frontend, indexierbar, ohne Login
|   /app/...     die Anwendung, ausschliesslich authentifiziert
|   /admin/...   interner Adminbereich, getrennte Rollen und 2FA
|   /webhooks/   Providerbenachrichtigungen, ohne Session und ohne CSRF
|
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withScopedSingletons([
        // Der aktive Mandant gilt genau eine Anfrage lang. Ein Singleton ueber
        // die Anfragegrenze hinweg waere im Queue-Worker ein Mandantenleck.
        OrganizationContext::class => OrganizationContext::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // HTTPS zuerst, damit eine unverschluesselte Anfrage erst gar nicht in
        // die Sitzungsbehandlung laeuft.
        $middleware->prependToGroup('web', ForceHttps::class);
        // Die www-Umleitung liegt VOR ForceHttps, damit www.<domain> in genau
        // einem Schritt auf die kanonische https-Adresse fuehrt.
        $middleware->prependToGroup('web', RedirectToCanonicalHost::class);
        // Sicherheitsheader global und nicht nur in der Gruppe "web": Ein 404
        // fuer einen unbekannten Pfad, ein 405 und die HTTPS-Umleitung
        // entstehen, bevor eine Routengruppe erreicht wird. Als globale
        // Middleware liegen die Header auf jeder Antwort, auch auf diesen.
        $middleware->append(SecurityHeaders::class);

        /*
         * Vertrauenswuerdige Proxys (config/deploy.php, TRUSTED_PROXIES).
         *
         * Zu diesem Zeitpunkt sind .env und Konfiguration noch nicht geladen.
         * Hier werden deshalb nur die Header festgelegt; die Adressen werden
         * im booted-Callback unten aus der Konfiguration uebernommen, bevor die
         * erste Anfrage die Middleware erreicht.
         */
        $middleware->trustProxies(headers: TrustedProxyConfiguration::HEADERS);

        $middleware->alias([
            'organisation' => EnsureOrganizationContext::class,
        ]);

        // Die Webhook-Routen der Zahlungsanbindung laufen ohne Session und
        // ohne CSRF-Token. Die Echtheit wird ausschliesslich ueber die
        // Signaturpruefung des Anbieters festgestellt (Masterprompt 15.1).
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        // Gaeste landen auf der Anmeldeseite, angemeldete Nutzer auf dem
        // Dashboard der Anwendung.
        $middleware->redirectTo(
            guests: fn (): string => route('login'),
            users: fn (): string => route('portal.dashboard'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Fachliche Ausnahmen der Anwendungsschicht werden nie als Stacktrace
        // ausgeliefert. Die Statusuebergangspruefung des Abrechnungslaufs
        // liefert eine verstaendliche deutsche Meldung, siehe
        // App\Application\BillingRun\IllegalStatusTransitionException.
        $exceptions->dontReport(
            IllegalStatusTransitionException::class,
        );
    })
    ->booted(function (): void {
        // Vertrauenswuerdige Proxys aus TRUSTED_PROXIES, siehe config/deploy.php.
        // Leer bedeutet: keinem Proxy vertrauen. "*" ist auf IONOS Webhosting
        // die praktikable Einstellung, die Abwaegung steht in der Konfiguration.
        TrustedProxyConfiguration::apply(config('deploy.trusted_proxies'));

        /*
         * Gate "access-admin"
         *
         * Die Adminroutengruppe in routes/web.php verwendet can:access-admin.
         * Adminrechte werden ausschliesslich aus der getrennten Tabelle
         * admin_roles abgeleitet, niemals aus einer Kundenrolle
         * (Masterprompt 19, ARCHITECTURE.md T10).
         *
         * TODO Phase 5: Fuer Adminrollen ist TOTP-2FA verpflichtend. Sobald der
         * Zweitfaktor umgesetzt ist, wird hier zusaetzlich
         * two_factor_confirmed_at geprueft. Bis dahin bleibt der Adminbereich
         * ohne Routen und damit ohne Einstiegspunkt.
         */
        Gate::define('access-admin', function (User $user): AccessResponse {
            if ($user->getAttribute('deleted_at') !== null) {
                return AccessResponse::denyWithStatus(404);
            }

            foreach (AdminRole::cases() as $role) {
                if ($user->hasAdminRole($role)) {
                    return AccessResponse::allow();
                }
            }

            return AccessResponse::denyWithStatus(404);
        });

        /*
         * Gate "email-verified"
         *
         * Vorgabe des Masterprompts, Abschnitt 8.1: Die E-Mail-Verifizierung
         * ist vor Zahlung und finalem Download verbindlich. Konto und Entwuerfe
         * bleiben davor nutzbar, damit ein Nutzer den gefuehrten Ablauf ohne
         * Huerde beginnen kann.
         *
         * Das Gate wird bewusst anstelle der Laravel-Middleware "verified"
         * verwendet: App\Models\User implementiert MustVerifyEmail derzeit
         * nicht, und eine pauschale Pflicht fuer den gesamten Portalbereich
         * waere ein Widerspruch zu Abschnitt 8.1. Ueber das Gate wird die
         * Pflicht punktgenau an den zahlungsnahen Handlungen und am finalen
         * Download durchgesetzt.
         */
        Gate::define('email-verified', function (User $user): AccessResponse {
            return $user->getAttribute('email_verified_at') !== null
                ? AccessResponse::allow()
                : AccessResponse::deny(
                    'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse. Den Bestätigungslink finden Sie in Ihrem Postfach.'
                );
        });
    })->create();
