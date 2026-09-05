<?php

declare(strict_types=1);

use App\Http\Controllers\Maintenance\CronController;
use App\Http\Controllers\ReminderUnsubscribeController;
use App\Http\Controllers\Webhook\StripeWebhookController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|------------------------------------------------------------------------------
| Webrouten
|------------------------------------------------------------------------------
|
| Die Anwendung liefert zwei Bereiche unter derselben kanonischen Domain
| https://smart-abrechnen.de aus (siehe ARCHITECTURE.md, ADR-002):
|
|   /            oeffentliches Frontend, indexierbar, ohne Login
|   /app/...     die Anwendung, ausschliesslich authentifiziert
|   /admin/...   interner Adminbereich, getrennte Rollen und 2FA
|   /webhooks/   Providerbenachrichtigungen, ohne Session und ohne CSRF
|
| Alle Portalrouten sind mandantenbezogen. Die Autorisierung erfolgt zusaetzlich
| in jeder Policy und in jedem Use Case, niemals allein ueber die URL.
|
*/

// --- Oeffentliches Frontend --------------------------------------------------

Route::name('site.')->group(function (): void {
    Route::view('/', 'site.home')->name('home');
    Route::view('/so-funktioniert-es', 'site.ablauf')->name('ablauf');
    Route::view('/preise', 'site.preise')->name('preise');
    Route::view('/datenschutz-und-loeschung', 'site.datenschutz-konzept')->name('datenschutz-konzept');
    Route::view('/haeufige-fragen', 'site.faq')->name('faq');
    Route::view('/kontakt', 'site.kontakt')->name('kontakt');
});

// --- Rechtstexte -------------------------------------------------------------
// Platzhalterseiten. Inhalte sind vor Livegang anwaltlich zu pruefen und
// freizugeben. Der Adminbereich zeigt bis dahin einen Livegang-Blocker.

Route::name('legal.')->group(function (): void {
    Route::view('/impressum', 'legal.impressum')->name('impressum');
    Route::view('/datenschutzerklaerung', 'legal.datenschutz')->name('datenschutz');
    Route::view('/agb', 'legal.agb')->name('agb');
    Route::view('/widerrufsbelehrung', 'legal.widerruf')->name('widerruf');
});

// --- Wartungsaufruf per URL --------------------------------------------------
//
// Fuer Hosting ohne Shell (Cronjob nur als Webadresse). Nur mit CRON_TOKEN
// aktiv, jeder Aufruf verlangt den Schluessel, siehe CronController.

// Ohne Session, Cookies und CSRF: Der Aufruf muss auch funktionieren, bevor
// die Datenbanktabellen fuer Sitzungen und Cache existieren (Erstinstallation).
Route::get('/wartung/{aufgabe}', CronController::class)
    ->middleware('throttle:wartung')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ])
    ->where('aufgabe', '[a-z-]+')
    ->name('wartung');

// --- Erinnerungen abmelden und wieder aktivieren -----------------------------
//
// Bewusst ohne Login erreichbar, damit eine Abmeldung nicht an einem
// vergessenen Passwort scheitert. Die Echtheit stellt die signierte URL sicher,
// der Token enthaelt keine Kundendaten. Kritische Konto- und Zahlungsmails sind
// von der Abmeldung nicht betroffen.

// GET zeigt nur eine Bestaetigungsseite, die Aenderung selbst erfolgt per
// POST. Link-Scanner und Vorschaudienste der Postfaecher rufen enthaltene
// Adressen automatisch ab und wuerden den Nutzer sonst unbemerkt abmelden.

Route::middleware('signed')->name('erinnerungen.')->group(function (): void {
    Route::get('/erinnerungen/abmelden/{token}', [ReminderUnsubscribeController::class, 'unsubscribe'])
        ->name('abmelden');
    Route::post('/erinnerungen/abmelden/{token}', [ReminderUnsubscribeController::class, 'confirmUnsubscribe'])
        ->name('abmelden.bestaetigen');
    Route::get('/erinnerungen/aktivieren/{token}', [ReminderUnsubscribeController::class, 'resubscribe'])
        ->name('aktivieren');
    Route::post('/erinnerungen/aktivieren/{token}', [ReminderUnsubscribeController::class, 'confirmResubscribe'])
        ->name('aktivieren.bestaetigen');
});

// --- Anwendung ---------------------------------------------------------------
//
// Der Bereich verlangt eine Anmeldung und einen gueltigen Mandantenkontext.
// Die E-Mail-Verifizierung ist hier bewusst NICHT vorgeschaltet: Konto und
// Entwuerfe sind kostenlos und sollen ohne Huerde nutzbar sein. Verbindlich ist
// die Verifizierung erst vor Zahlung und vor dem finalen Download; sie wird
// dort ueber das Gate "email-verified" an der einzelnen Route durchgesetzt.

Route::prefix('app')
    ->name('portal.')
    ->middleware(['auth'])
    ->group(base_path('routes/portal.php'));

// --- Adminbereich ------------------------------------------------------------
//
// Die bestaetigte E-Mail-Adresse wird ueber das eigene Gate email-verified
// verlangt. Die Laravel-Middleware verified prueft nur Modelle, die
// MustVerifyEmail implementieren; App\Models\User tut das bewusst nicht, die
// Middleware waere hier wirkungslos.

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'can:email-verified', 'can:access-admin'])
    ->group(base_path('routes/admin.php'));

// --- Webhooks ----------------------------------------------------------------
//
// Ohne Session und ohne CSRF-Token; die Ausnahme ist in bootstrap/app.php
// gesetzt. Die Echtheit wird ausschliesslich ueber die Signaturpruefung des
// Anbieters festgestellt. Nur ein verifiziertes Ereignis schaltet die
// Finalisierung frei, der Browser-Redirect niemals.

Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->middleware('throttle:webhooks')
    ->name('webhooks.stripe');

require __DIR__.'/auth.php';
