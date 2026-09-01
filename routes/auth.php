<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Authentifizierungsrouten
|------------------------------------------------------------------------------
|
| Registrierung, Anmeldung, E-Mail-Verifizierung und Passwort-Reset.
|
| RATENBEGRENZUNG (Masterprompt 8.1, 19, ARCHITECTURE.md T9)
|
|   anmeldung        10 Anfragen je Minute und IP als grobe Bremse. Die feine
|                    Sperre je Konto und IP liegt in
|                    App\Http\Requests\Auth\LoginRequest und meldet die
|                    Wartezeit in deutscher Sprache.
|   registrierung     5 Anfragen je Minute und IP
|   passwort-reset    5 Anfragen je Minute und IP, zusaetzlich die Drosselung
|                    des Laravel-Passwortbrokers je Konto
|   verifizierung     6 Anfragen je Minute und IP, zusaetzlich ein Limit je
|                    Nutzer und Stunde im Controller
|
| Die Route zur Anmeldung heisst "login", weil die Laravel-Middleware auth
| Gaeste auf genau diesen Namen umleitet.
|
| Die Bestaetigungsroute traegt die Middleware signed. Eine manipulierte oder
| abgelaufene URL wird mit 403 abgewiesen, ohne Auskunft ueber das Konto.
|
| TOTP-2FA (Masterprompt 8.1): fuer Kunden optional, fuer Adminrollen
| verpflichtend. Die Codeeingabe liegt bewusst NICHT in der Gruppe auth: Nach
| der Passwortpruefung ist eine Sitzung mit offenem zweiten Faktor kein
| angemeldeter Nutzer, sondern merkt sich nur die Kennung des Kontos. Damit
| erreicht sie keinen geschuetzten Bereich. Die Ratenbegrenzung des zweiten
| Schritts ist eigenstaendig, siehe
| App\Http\Controllers\Auth\TwoFactorChallengeController.
|
*/

Route::middleware('guest')->group(function (): void {
    Route::get('/registrieren', [RegisteredUserController::class, 'create'])
        ->name('register');
    Route::post('/registrieren', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:5,1');

    Route::get('/anmelden', [AuthenticatedSessionController::class, 'create'])
        ->name('login');
    Route::post('/anmelden', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1');

    Route::get('/passwort-vergessen', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');
    Route::post('/passwort-vergessen', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    Route::get('/passwort-neu/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('/passwort-neu', [NewPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.update');
});

// --- Zweiter Faktor, Codeeingabe ---------------------------------------------
//
// Ohne Middleware auth, weil die Sitzung zwischen Passwort und Code bewusst
// nicht angemeldet ist. Der Zugang ist allein ueber den Sitzungsschluessel
// moeglich, den der erste Schritt gesetzt hat.

Route::prefix('/zwei-faktor')->name('two-factor.')->group(function (): void {
    Route::get('/code', [TwoFactorChallengeController::class, 'create'])->name('challenge');
    Route::post('/code', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('challenge.store');
    Route::post('/abbrechen', [TwoFactorChallengeController::class, 'abort'])->name('abort');
});

Route::middleware('auth')->prefix('/zwei-faktor')->name('two-factor.')->group(function (): void {
    Route::get('/einrichten', [TwoFactorSetupController::class, 'show'])->name('setup');
    Route::post('/einrichten', [TwoFactorSetupController::class, 'start'])
        ->middleware('throttle:10,1')
        ->name('setup.start');
    Route::post('/bestaetigen', [TwoFactorSetupController::class, 'confirm'])
        ->middleware('throttle:10,1')
        ->name('confirm');
    Route::post('/abschalten', [TwoFactorSetupController::class, 'disable'])
        ->middleware('throttle:10,1')
        ->name('disable');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/e-mail-bestaetigen', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');

    Route::post('/e-mail-bestaetigen', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::post('/abmelden', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

// Die Bestaetigung selbst ist bewusst ohne auth erreichbar. Der Nutzer oeffnet
// den Link haeufig in einem anderen Browser als dem, in dem er sich registriert
// hat. Die Echtheit sichert die Signatur der URL.
Route::get('/e-mail-bestaetigen/{user}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
