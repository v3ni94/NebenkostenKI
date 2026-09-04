<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AiController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CommunicationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\InvoiceCancellationController;
use App\Http\Controllers\Admin\LaunchBlockerController;
use App\Http\Controllers\Admin\MetricsController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PaymentRecoveryController;
use App\Http\Controllers\Admin\PricingController;
use App\Http\Controllers\Admin\PrivacyController;
use App\Http\Controllers\Admin\ProcessingController;
use App\Http\Controllers\Admin\SupportAccessController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VersionController;
use App\Http\Middleware\RequireAdminTwoFactor;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Routen des Adminbereichs (Prefix /admin)
|------------------------------------------------------------------------------
|
| Nur interne Rollen, 2FA verpflichtend, getrennt von Kundensitzungen. Jeder
| Supportzugriff auf eine Organisation, ein Objekt oder einen Abrechnungslauf
| erzeugt einen Audit-Log-Eintrag.
|
| Die Gruppe erbt aus routes/web.php bereits auth, can:email-verified und
| can:access-admin. Das Gate leitet Adminrechte ausschliesslich aus der
| getrennten Tabelle admin_roles ab, niemals aus einer Kundenrolle.
|
| Schreibende Handlungen tragen zusaetzlich ein Gate je Handlungsklasse
| (bootstrap/app.php): admin-manage-users, admin-cancel-invoices,
| admin-retry-jobs. Damit erhaelt eine Support- oder Finanzkennung nicht
| saemtliche Rechte der Administration.
|
| RequireAdminTwoFactor wird hier und nicht in bootstrap/app.php angehaengt.
| Damit bleibt die Wirkung auf den Adminbereich begrenzt und an genau einer
| Stelle ablesbar. Die Middleware prueft zwei Voraussetzungen einer
| Adminsitzung: das Bestaetigungsmerkmal des Zweitfaktors, das produktiv
| verpflichtend ist, und den Kontostatus.
|
| Schreibende Handlungen sind ausschliesslich POST. Es gibt im Adminbereich
| keine zustandsaendernde GET-Route.
|
*/

Route::middleware(RequireAdminTwoFactor::class)->group(function (): void {

    // --- Uebersicht und Livegang ---------------------------------------------

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/livegang', [LaunchBlockerController::class, 'index'])->name('livegang');

    // --- Datenschutzmonitor --------------------------------------------------

    Route::get('/datenschutz', [PrivacyController::class, 'index'])->name('datenschutz');
    Route::post('/datenschutz/loeschungen/wiederholen', [PrivacyController::class, 'retry'])
        ->middleware('can:admin-retry-jobs')
        ->name('datenschutz.wiederholen');

    // --- Verarbeitung und Teiljobs ------------------------------------------

    Route::get('/verarbeitung', [ProcessingController::class, 'index'])->name('verarbeitung');
    Route::post('/verarbeitung/jobs/{job}/wiederholen', [ProcessingController::class, 'retryJob'])
        ->middleware('can:admin-retry-jobs')
        ->name('verarbeitung.wiederholen');

    // --- KI ------------------------------------------------------------------

    Route::get('/ki', [AiController::class, 'index'])->name('ki');

    // --- Zahlungen, Rechnungen und Stornos ----------------------------------

    Route::get('/zahlungen', [PaymentController::class, 'index'])->name('zahlungen');
    Route::get('/rechnungen/{invoice}/storno', [InvoiceCancellationController::class, 'create'])
        ->middleware('can:admin-cancel-invoices')
        ->name('rechnungen.storno.create');
    Route::post('/rechnungen/{invoice}/storno', [InvoiceCancellationController::class, 'store'])
        ->middleware('can:admin-cancel-invoices')
        ->name('rechnungen.storno.store');

    // --- Preise und Umsatzsteuer --------------------------------------------

    Route::get('/preise', [PricingController::class, 'index'])->name('preise');
    Route::post('/preise/pruefen', [PricingController::class, 'check'])->name('preise.pruefen');

    // --- Nutzer --------------------------------------------------------------

    Route::get('/nutzer', [UserController::class, 'index'])->name('nutzer');

    Route::middleware('can:admin-manage-users')->group(function (): void {
        Route::post('/nutzer/{user}/sperren', [UserController::class, 'lock'])->name('nutzer.sperren');
        Route::post('/nutzer/{user}/entsperren', [UserController::class, 'unlock'])->name('nutzer.entsperren');
        Route::post('/nutzer/{user}/passwort', [UserController::class, 'passwordReset'])->name('nutzer.passwort');
        Route::post('/nutzer/{user}/zweitfaktor-zuruecksetzen', [UserController::class, 'resetTwoFactor'])
            ->name('nutzer.zweitfaktor');
    });

    // --- Supportzugriff auf Kundendaten -------------------------------------
    //
    // Der Einblick verlangt vorher eine Begruendung. Ohne Freischaltung leitet
    // jede Detailseite auf dieses Formular.

    Route::get('/supportzugriff/{entitaet}/{id}', [SupportAccessController::class, 'create'])
        ->name('support.begruendung');
    Route::post('/supportzugriff/{entitaet}/{id}', [SupportAccessController::class, 'store'])
        ->name('support.freigeben');

    Route::get('/organisationen', [OrganizationController::class, 'index'])->name('organisationen');
    Route::get('/organisationen/{organization}', [OrganizationController::class, 'show'])
        ->name('organisationen.show');
    Route::get('/objekte/{property}', [OrganizationController::class, 'showProperty'])
        ->name('objekte.show');
    Route::get('/abrechnungen/{billingRun}', [OrganizationController::class, 'showBillingRun'])
        ->name('abrechnungen.show');

    // --- Kommunikation, Versionen, Technik, Kennzahlen, Protokoll -----------

    Route::get('/kommunikation', [CommunicationController::class, 'index'])->name('kommunikation');
    Route::post('/kommunikation/sperrliste/aufheben', [CommunicationController::class, 'releaseSuppression'])
        ->name('kommunikation.sperre.aufheben');
    Route::get('/versionen', [VersionController::class, 'index'])->name('versionen');
    Route::get('/technik', [HealthController::class, 'index'])->name('technik');
    Route::get('/kennzahlen', [MetricsController::class, 'index'])->name('kennzahlen');
    Route::get('/protokoll', [AuditLogController::class, 'index'])->name('protokoll');
});

// --- Zahlungsnachlauf ----------------------------------------------------------
// Bezahlte Laeufe ohne Finalisierung oder ohne Rechnung nachholen und
// Zahlungseingaenge ohne freischaltbaren Lauf sichtbar machen.

Route::middleware(RequireAdminTwoFactor::class)->group(function (): void {
    Route::get('/zahlungsnachlauf', [PaymentRecoveryController::class, 'index'])->name('zahlungsnachlauf');
    Route::post('/zahlungsnachlauf/{billingRun}/finalisieren', [PaymentRecoveryController::class, 'finalize'])
        ->name('zahlungsnachlauf.finalisieren');
    Route::post('/zahlungsnachlauf/{billingRun}/rechnung', [PaymentRecoveryController::class, 'issueInvoice'])
        ->name('zahlungsnachlauf.rechnung');
});
