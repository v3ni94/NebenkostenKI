<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\AccountController;
use App\Http\Controllers\Portal\BillingRunController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\DownloadController;
use App\Http\Controllers\Portal\FollowUpYearController;
use App\Http\Controllers\Portal\PropertyController;
use App\Http\Controllers\Portal\Review\AnalysisStatusController;
use App\Http\Controllers\Portal\Review\BillingModeController;
use App\Http\Controllers\Portal\Review\CostReviewController;
use App\Http\Controllers\Portal\Review\HeatingMatrixController;
use App\Http\Controllers\Portal\TenancyController;
use App\Http\Controllers\Portal\UnitController;
use App\Http\Controllers\Portal\Upload\ChunkUploadController;
use App\Http\Controllers\Portal\Upload\UploadStatusController;
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
| Upload und Download haben eigene, gesondert ratenbegrenzte Routen. Zahlung
| und Vorschau folgen in den spaeteren Phasen.
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

    // --- Analyse, Kostenpruefung und Abrechnungsweg --------------------------
    //
    // Schritt 3 bis 6 des gefuehrten Ablaufs. Die Oberflaeche arbeitet
    // ausschliesslich auf den strukturierten Extraktionsdaten; die
    // Originaldateien sind zu diesem Zeitpunkt bereits geloescht.
    //
    // Reihenfolge beachten: die festen Segmente stehen vor {costItem}, sonst
    // faengt der Platzhalter "sammelbestaetigung" und "weiter" ab.

    Route::get('/abrechnungen/{billingRun}/analyse', [AnalysisStatusController::class, 'show'])
        ->name('pruefung.analyse');
    Route::get('/abrechnungen/{billingRun}/analyse/status', [AnalysisStatusController::class, 'status'])
        ->name('pruefung.analyse.status');
    Route::post('/abrechnungen/{billingRun}/analyse/zuordnen', [AnalysisStatusController::class, 'reconcile'])
        ->name('pruefung.zuordnen');

    Route::get('/abrechnungen/{billingRun}/kostenpruefung', [CostReviewController::class, 'index'])
        ->name('pruefung.kosten');
    Route::post('/abrechnungen/{billingRun}/kostenpruefung/positionen', [CostReviewController::class, 'store'])
        ->name('pruefung.kosten.store');
    Route::post('/abrechnungen/{billingRun}/kostenpruefung/sammelbestaetigung', [CostReviewController::class, 'bulkConfirm'])
        ->name('pruefung.sammelbestaetigung');
    Route::post('/abrechnungen/{billingRun}/kostenpruefung/weiter', [CostReviewController::class, 'proceed'])
        ->name('pruefung.weiter');
    Route::post('/abrechnungen/{billingRun}/kostenpruefung/{costItem}/bestaetigen', [CostReviewController::class, 'confirm'])
        ->name('pruefung.kosten.bestaetigen');
    Route::post('/abrechnungen/{billingRun}/kostenpruefung/{costItem}/verwerfen', [CostReviewController::class, 'discard'])
        ->name('pruefung.kosten.verwerfen');
    Route::post('/abrechnungen/{billingRun}/kostenpruefung/{costItem}/ausschliessen', [CostReviewController::class, 'exclude'])
        ->name('pruefung.kosten.ausschliessen');
    Route::post('/abrechnungen/{billingRun}/kostenpruefung/{costItem}/einheit', [CostReviewController::class, 'assign'])
        ->name('pruefung.kosten.einheit');
    Route::put('/abrechnungen/{billingRun}/kostenpruefung/{costItem}', [CostReviewController::class, 'update'])
        ->name('pruefung.kosten.update');

    Route::get('/abrechnungen/{billingRun}/heizkosten', [HeatingMatrixController::class, 'show'])
        ->name('pruefung.heizkosten');

    Route::get('/abrechnungen/{billingRun}/abrechnungsweg', [BillingModeController::class, 'edit'])
        ->name('pruefung.weg.edit');
    Route::put('/abrechnungen/{billingRun}/abrechnungsweg', [BillingModeController::class, 'update'])
        ->name('pruefung.weg.update');

    // --- Upload und Verarbeitungsstatus --------------------------------------
    //
    // Der Upload laeuft in Abschnitten (Chunks), damit die Post-Limits von
    // IONOS nicht zum Abbruch fuehren. Die Originaldateien liegen ausschliesslich
    // im temporaeren Bereich und werden nach der Auswertung geloescht.

    Route::get('/abrechnungen/{billingRun}/upload', [UploadStatusController::class, 'show'])
        ->name('uploads.index');
    Route::get('/abrechnungen/{billingRun}/uploads/status', [UploadStatusController::class, 'index'])
        ->name('uploads.status');
    Route::get('/uploads/{upload}', [UploadStatusController::class, 'upload'])
        ->name('uploads.show');

    Route::middleware('throttle:uploads')->group(function (): void {
        Route::post('/abrechnungen/{billingRun}/uploads', [ChunkUploadController::class, 'store'])
            ->name('uploads.store');
        Route::post('/uploads/{upload}/abschnitte', [ChunkUploadController::class, 'storeChunk'])
            ->name('uploads.chunk');
        Route::post('/uploads/{upload}/abschluss', [ChunkUploadController::class, 'complete'])
            ->name('uploads.complete');
    });

    // --- Downloads erzeugter Artefakte ---------------------------------------
    //
    // Ausgeliefert werden ausschliesslich vom System erzeugte Dateien, niemals
    // Originaluploads. Jeder Abruf prueft die Eigentuemerschaft. Die
    // E-Mail-Verifizierung ist fuer den finalen Download verbindlich
    // (Masterprompt 8.1).

    Route::middleware(['throttle:downloads', 'can:email-verified'])->group(function (): void {
        Route::get('/downloads/{generatedDocument}', [DownloadController::class, 'stream'])
            ->name('downloads.stream');
    });

    // --- Folgejahresuebernahme -----------------------------------------------
    //
    // Einstieg aus der Erinnerungsmail. Die signierte URL verhindert, dass ein
    // weitergeleiteter Link dauerhaft nutzbar bleibt; die Autorisierung erfolgt
    // zusaetzlich ueber Anmeldung, Mandantenkontext und Policy.

    Route::get('/objekte/{property}/folgejahr/{jahr}', [FollowUpYearController::class, 'start'])
        ->middleware('signed')
        ->name('folgejahr.start');

    // --- Konto ---------------------------------------------------------------

    Route::get('/konto', [AccountController::class, 'edit'])->name('konto.edit');
    Route::put('/konto', [AccountController::class, 'update'])->name('konto.update');
    Route::put('/konto/e-mail', [AccountController::class, 'updateEmail'])->name('konto.email');
    Route::put('/konto/erinnerungen', [AccountController::class, 'updateReminders'])->name('konto.erinnerungen');
});

// --- Signierter Download ------------------------------------------------------
//
// Kurzlebiger signierter Link aus einer Transaktionsmail. Die Signatur ersetzt
// nicht die Autorisierung: der Controller prueft zusaetzlich die
// Eigentuemerschaft. Gueltigkeitsdauer aus SIGNED_DOWNLOAD_TTL_MINUTES.

Route::get('/downloads/{generatedDocument}/signiert', [DownloadController::class, 'signed'])
    ->middleware(['signed', 'throttle:downloads'])
    ->name('downloads.signed');
