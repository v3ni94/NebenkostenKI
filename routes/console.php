<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|------------------------------------------------------------------------------
| Konsolenbefehle und Zeitplan
|------------------------------------------------------------------------------
|
| BETRIEBSMODELL (ARCHITECTURE.md ADR-006, Masterprompt 3.5):
|
| Auf IONOS Profil A gibt es weder Redis noch einen dauerhaften Worker. Ein
| Cronjob des IONOS-Kontos ruft in kurzen Abstaenden auf:
|
|     /usr/bin/php8.3 /ABSOLUTER/PFAD/artisan schedule:run
|
| Der tatsaechliche PHP-Pfad und der Document Root werden im IONOS-Konto
| ermittelt und nicht geraten. Steht nur ein Fuenf-Minuten-Intervall zur
| Verfuegung, stellt die Oberflaeche das ehrlich dar und behauptet keine
| Echtzeitverarbeitung.
|
| Der Zeitplan haelt drei Aufgaben:
|
|   1. Queue-Slice: verarbeitet faellige Teiljobs mit begrenzter Laufzeit.
|   2. TTL-Cleanup: loescht ueberfaellige Originaluploads, unabhaengig davon,
|      ob die Verarbeitung haengen geblieben ist. Datenschutzkritisch.
|   3. Wiederholung fehlgeschlagener Loeschungen: haelt den Loeschnachweis
|      vollstaendig und leert die Datenschutzalarme des Adminbereichs.
|
| Alle drei Eintraege laufen ohne Ueberlappung. Ein haengender Lauf blockiert
| damit nicht den naechsten, und es entsteht keine Doppelverarbeitung.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- Teiljobs der Dokumentverarbeitung ---------------------------------------
// Kurzer Lauf, damit ein Aufruf sicher innerhalb der Prozesslaufzeit eines
// Webhosting-Tarifs endet. Begonnene Teiljobs bleiben wiederanlaufbar.

Schedule::command('smartabrechnen:queue-slice', ['--max-time=45', '--max-jobs=100'])
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->description('Verarbeitet fällige Teiljobs der Dokumentverarbeitung.');

// --- TTL-Cleanup des Kurzzeitbereichs ----------------------------------------
// Intervall aus TEMP_CLEANUP_INTERVAL_MINUTES. Der Lauf ist idempotent und
// unabhaengig von Worker, Provider und Browsersitzung.

Schedule::command('smartabrechnen:cleanup-temporary-uploads', ['--batch=100'])
    ->cron(sprintf(
        '*/%d * * * *',
        min(59, max(1, (int) config('smartabrechnen.retention.temp_cleanup_interval_minutes', 10)))
    ))
    ->withoutOverlapping(10)
    ->runInBackground()
    ->description('Löscht überfällige Originaluploads aus dem Kurzzeitbereich.');

// --- Wiederholung fehlgeschlagener Loeschungen -------------------------------
// Eine fehlgeschlagene Loeschung ist ein offener Datenschutzvorfall. Sie wird
// haeufiger wiederholt als eine gewoehnliche Aufgabe.

Schedule::command('smartabrechnen:retry-failed-deletions', ['--batch=50'])
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->description('Wiederholt fehlgeschlagene Löschungen von Quelldaten.');
