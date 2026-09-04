<?php

declare(strict_types=1);

use App\Application\Install\SchedulerHeartbeat;
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

// --- Lebenszeichen des Schedulers --------------------------------------------
// Setzt bei jedem Lauf einen Zeitstempel im Cache. smartabrechnen:check-config
// liest ihn und meldet, ob der Cronjob des IONOS-Control-Centers tatsaechlich
// schedule:run aufruft. Kein Hintergrundprozess, kein Netzzugriff.

Schedule::call(static function (SchedulerHeartbeat $heartbeat): void {
    $heartbeat->record();
})
    ->everyMinute()
    ->name('smartabrechnen:scheduler-heartbeat')
    ->description('Zeitstempel des letzten Schedulerlaufs für den Konfigurationscheck.');

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

// --- Automatische Erinnerungen fuer Folgejahre -------------------------------
//
// Masterprompt 17: Erinnerungen am 15. Januar, 15. April, 15. Juli und am
// 1. Dezember, Zeitzone Europe/Berlin, jeweils konfigurierbar.
//
// Der Eintrag laeuft taeglich. Der Befehl entscheidet selbst, ob heute ein
// Erinnerungstermin ist, damit eine Aenderung der Konfiguration ohne
// Anpassung des Zeitplans wirkt.
//
// IDEMPOTENZ: Der Lauf ist taggleich mehrfach ausfuehrbar. Die Dublettensperre
// liegt im eindeutigen deduplication_key der Tabelle reminder_events. Ein
// mehrfacher Cronlauf am selben Tag erzeugt deshalb keine zweite Mail.

Schedule::command('smartabrechnen:send-reminders')
    ->dailyAt('07:00')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->description('Versendet die automatischen Erinnerungen für Folgejahre.');

// --- Endgueltige Kontoloeschungen nach Ablauf der Frist ----------------------
//
// Masterprompt 19: Konto-Loeschworkflow mit dokumentierter Frist. Der Nutzer
// beantragt die Loeschung, kann sie innerhalb der Frist zurueckziehen, und nach
// Ablauf fuehrt dieser Lauf sie endgueltig aus.
//
// Der Lauf laeuft taeglich in der Nacht, weil er Daten unwiederbringlich
// entfernt und deshalb nicht mit der Tagesnutzung konkurrieren soll. Er ist
// idempotent und wiederaufnehmbar: Ausgewaehlt werden nur Konten mit offenem
// Antrag und abgelaufener Frist. Ein Fehlschlag laesst den Antrag offen, sodass
// der naechste Lauf ihn erneut aufnimmt.

Schedule::command('smartabrechnen:execute-account-deletions', ['--batch=25'])
    ->dailyAt('03:20')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->description('Führt beantragte Kontolöschungen nach Ablauf der Frist aus.');

// --- Aufbewahrungsfristen ----------------------------------------------------
//
// Masterprompt 19: abgelaufene strukturierte Extraktionsdaten und abgelaufene
// erzeugte PDFs werden entfernt. Sind EXTRACTED_DATA_RETENTION_DAYS und
// GENERATED_PDF_RETENTION_DAYS nicht gesetzt, loescht der Lauf ausdruecklich
// nichts und meldet den offenen Punkt. Der Lauf ist idempotent.

Schedule::command('smartabrechnen:enforce-retention')
    ->dailyAt('03:40')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->description('Setzt die Aufbewahrungsfristen für Extraktionsdaten und erzeugte PDFs durch.');

// --- Nachlauf nach bestaetigter Zahlung --------------------------------------
//
// Ein Kunde, der bezahlt hat, muss seine Leistung erhalten. Scheitert die
// Finalisierung im Webhook (etwa Artefaktspeicher nicht erreichbar), bleibt
// der Lauf bezahlt in FAILED; fehlen die Betreiberstammdaten, bleibt ein
// finalisierter Lauf ohne Rechnung. Beides wird hier regelmaessig nachgeholt.
// Der Lauf ist idempotent; offene Faelle bleiben im Adminbereich sichtbar.

Schedule::command('smartabrechnen:retry-finalization', ['--batch=25'])
    ->everyFifteenMinutes()
    ->withoutOverlapping(15)
    ->runInBackground()
    ->description('Holt Finalisierung und Rechnung für bezahlte Abrechnungsläufe nach.');
