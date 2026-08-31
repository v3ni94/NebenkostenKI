<?php

declare(strict_types=1);

use App\Application\Account\OrganizationContext;
use App\Application\BillingRun\IllegalStatusTransitionException;
use App\Enums\AdminRole;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Middleware\ForceHttps;
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
        // die Sitzungsbehandlung laeuft. Sicherheitsheader am Ende der Gruppe,
        // damit sie auf jeder Antwort liegen, auch auf Fehlerseiten.
        $middleware->prependToGroup('web', ForceHttps::class);
        $middleware->appendToGroup('web', SecurityHeaders::class);

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
         * nicht, und die Portalroutengruppe traegt "verified" pauschal fuer den
         * gesamten Bereich. Ueber das Gate wird die Pflicht punktgenau an den
         * zahlungsnahen Handlungen durchgesetzt.
         */
        Gate::define('email-verified', function (User $user): AccessResponse {
            return $user->getAttribute('email_verified_at') !== null
                ? AccessResponse::allow()
                : AccessResponse::deny(
                    'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse. Den Bestätigungslink finden Sie in Ihrem Postfach.'
                );
        });
    })->create();
