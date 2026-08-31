<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\AccountController;
use App\Http\Controllers\Portal\BillingRunController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\PropertyController;
use App\Http\Controllers\Portal\TenancyController;
use App\Http\Controllers\Portal\UnitController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Routen der Anwendung (Prefix /app)
|------------------------------------------------------------------------------
|
| Die Gruppe erhaelt in routes/web.php bereits auth. Hier kommt der
| Mandantenkontext hinzu. Die E-Mail-Verifizierung ist bewusst nicht
| vorgeschaltet, sie greift erst vor Zahlung und finalem Download ueber das
| Gate email-verified an der einzelnen Route.
|
| MANDANTENSCHUTZ, verbindlich (Masterprompt 19, ARCHITECTURE.md T1)
|
|  1. Die Middleware organisation setzt die aktive Organisation und weist
|     Zugriffe ohne Mitgliedschaft ab.
|  2. Es wird ausdruecklich KEIN implizites Route-Model-Binding verwendet. Die
|     Parameter sind Zeichenketten, die Controller laden die Entitaet
|     ausschliesslich ueber eine bereits auf den Mandanten gescopte Query. Ein
|     fremder Datensatz ist damit nicht auffindbar und fuehrt zu 404, ohne dass
|     die Meldung seine Existenz verraet.
|  3. Zusaetzlich entscheidet je Aktion die Policy objektbezogen.
|
| Es gibt keine Einzelseitenanwendung. Jede Aenderung ist ein normaler
| POST-, PUT- oder DELETE-Aufruf mit anschliessender Weiterleitung und
| Statusmeldung. Der Ablauf ist damit jederzeit unterbrechbar.
|
| Zahlung, Upload, Vorschau und Download folgen in den spaeteren Phasen und
| erhalten dann eigene, gesondert ratenbegrenzte Routen.
|
*/

Route::middleware('organisation')->group(function (): void {

    // --- Dashboard -----------------------------------------------------------

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // --- Objekte -------------------------------------------------------------

    Route::get('/objekte', [PropertyController::class, 'index'])->name('objekte.index');
    Route::get('/objekte/neu', [PropertyController::class, 'create'])->name('objekte.create');
    Route::post('/objekte', [PropertyController::class, 'store'])->name('objekte.store');
    Route::get('/objekte/{property}/bearbeiten', [PropertyController::class, 'edit'])->name('objekte.edit');
    Route::put('/objekte/{property}', [PropertyController::class, 'update'])->name('objekte.update');
    Route::delete('/objekte/{property}', [PropertyController::class, 'destroy'])->name('objekte.destroy');

    // --- Einheiten -----------------------------------------------------------

    Route::get('/objekte/{property}/einheiten', [UnitController::class, 'index'])->name('einheiten.index');
    Route::get('/objekte/{property}/einheiten/neu', [UnitController::class, 'create'])->name('einheiten.create');
    Route::post('/objekte/{property}/einheiten', [UnitController::class, 'store'])->name('einheiten.store');
    Route::get('/einheiten/{unit}/bearbeiten', [UnitController::class, 'edit'])->name('einheiten.edit');
    Route::put('/einheiten/{unit}', [UnitController::class, 'update'])->name('einheiten.update');
    Route::delete('/einheiten/{unit}', [UnitController::class, 'destroy'])->name('einheiten.destroy');

    // --- Mietverhaeltnisse, Belegung und Leerstand ---------------------------

    Route::get('/einheiten/{unit}/mietverhaeltnisse', [TenancyController::class, 'index'])
        ->name('mietverhaeltnisse.index');
    Route::get('/einheiten/{unit}/mietverhaeltnisse/neu', [TenancyController::class, 'create'])
        ->name('mietverhaeltnisse.create');
    Route::post('/einheiten/{unit}/mietverhaeltnisse', [TenancyController::class, 'store'])
        ->name('mietverhaeltnisse.store');
    Route::get('/mietverhaeltnisse/{tenancy}/bearbeiten', [TenancyController::class, 'edit'])
        ->name('mietverhaeltnisse.edit');
    Route::put('/mietverhaeltnisse/{tenancy}', [TenancyController::class, 'update'])
        ->name('mietverhaeltnisse.update');
    Route::delete('/mietverhaeltnisse/{tenancy}', [TenancyController::class, 'destroy'])
        ->name('mietverhaeltnisse.destroy');

    Route::post('/einheiten/{unit}/leerstand', [TenancyController::class, 'storeVacancy'])
        ->name('leerstand.store');
    Route::delete('/leerstand/{vacancy}', [TenancyController::class, 'destroyVacancy'])
        ->name('leerstand.destroy');

    Route::post('/mietverhaeltnisse/{tenancy}/belegung', [TenancyController::class, 'storeOccupancy'])
        ->name('belegung.store');
    Route::delete('/belegung/{occupancy}', [TenancyController::class, 'destroyOccupancy'])
        ->name('belegung.destroy');

    // --- Abrechnungslaeufe ---------------------------------------------------

    Route::get('/abrechnungen', [BillingRunController::class, 'index'])->name('abrechnungen.index');
    Route::get('/abrechnungen/neu', [BillingRunController::class, 'create'])->name('abrechnungen.create');
    Route::post('/abrechnungen', [BillingRunController::class, 'store'])->name('abrechnungen.store');
    Route::get('/abrechnungen/{billingRun}', [BillingRunController::class, 'show'])->name('abrechnungen.show');

    // Die Nutzerbestaetigung ist der letzte Schritt vor der Zahlung. Die
    // E-Mail-Verifizierung ist ab hier verbindlich (Masterprompt 8.1).
    Route::post('/abrechnungen/{billingRun}/bestaetigen', [BillingRunController::class, 'confirm'])
        ->middleware('can:email-verified')
        ->name('abrechnungen.bestaetigen');

    Route::post('/abrechnungen/{billingRun}/abbrechen', [BillingRunController::class, 'cancel'])
        ->name('abrechnungen.abbrechen');
    Route::delete('/abrechnungen/{billingRun}', [BillingRunController::class, 'destroy'])
        ->name('abrechnungen.destroy');

    // --- Konto ---------------------------------------------------------------

    Route::get('/konto', [AccountController::class, 'edit'])->name('konto.edit');
    Route::put('/konto', [AccountController::class, 'update'])->name('konto.update');
    Route::put('/konto/e-mail', [AccountController::class, 'updateEmail'])->name('konto.email');
    Route::put('/konto/erinnerungen', [AccountController::class, 'updateReminders'])->name('konto.erinnerungen');
});
