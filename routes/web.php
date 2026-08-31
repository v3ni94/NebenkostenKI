<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

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

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'can:access-admin'])
    ->group(base_path('routes/admin.php'));

require __DIR__.'/auth.php';
