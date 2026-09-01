<?php

declare(strict_types=1);

namespace Tests\Feature\Reminder;

use App\Http\Controllers\Portal\FollowUpYearController;
use App\Http\Controllers\ReminderUnsubscribeController;
use Illuminate\Support\Facades\Route;

/**
 * Registriert die Routen dieses Arbeitspakets.
 *
 * Die endgueltige Registrierung erfolgt durch das Routenpaket in
 * routes/web.php und routes/portal.php. Damit die Tests unabhaengig davon
 * laufen, legt dieser Trait dieselben Namen, Pfade und Middleware an.
 *
 *   erinnerungen.abmelden     GET  /erinnerungen/abmelden/{token}     signed
 *   erinnerungen.aktivieren   GET  /erinnerungen/aktivieren/{token}   signed
 *   portal.folgejahr.start    GET  /app/objekte/{property}/folgejahr/{jahr}
 *                                                                    auth, signed
 */
trait PaketRouten
{
    protected function registriereRouten(): void
    {
        // Die Routen stehen inzwischen zentral in routes/web.php und
        // routes/portal.php. Diese Registrierung bleibt nur als Rueckfallebene
        // bestehen und wird uebersprungen, wenn die zentrale Definition
        // bereits geladen ist.
        if (Route::has('erinnerungen.abmelden')) {
            return;
        }

        Route::middleware(['web', 'signed'])->group(function (): void {
            Route::get('/erinnerungen/abmelden/{token}', [ReminderUnsubscribeController::class, 'unsubscribe'])
                ->name('erinnerungen.abmelden');

            Route::get('/erinnerungen/aktivieren/{token}', [ReminderUnsubscribeController::class, 'resubscribe'])
                ->name('erinnerungen.aktivieren');
        });

        Route::middleware(['web', 'auth', 'signed'])
            ->get('/app/objekte/{property}/folgejahr/{jahr}', [FollowUpYearController::class, 'start'])
            ->name('portal.folgejahr.start');

        // Die Namensaufloesung wird beim Start aufgebaut. Nachtraeglich
        // registrierte Routen sind erst nach dieser Auffrischung ueber ihren
        // Namen erreichbar.
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
}
