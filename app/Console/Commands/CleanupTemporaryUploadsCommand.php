<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Documents\CleanupExpiredUploads;
use Illuminate\Console\Command;

/**
 * Unabhaengiger TTL-Cleanup des Kurzzeitbereichs.
 *
 * Der Befehl laeuft ueber den Scheduler in kurzen Abstaenden und ist der
 * verbindliche Rueckfallpfad des Loeschkonzepts: Er loescht ueberfaellige
 * Originaldateien auch dann, wenn die Verarbeitung haengen geblieben ist, ein
 * Providerabruf nie zurueckkam oder der Browser geschlossen wurde
 * (Abschnitt 6.3 Schritt 17, Abschnitt 19).
 *
 * Der Lauf ist idempotent. Zweimal ausgefuehrt aendert der zweite Lauf nichts.
 */
final class CleanupTemporaryUploadsCommand extends Command
{
    protected $signature = 'smartabrechnen:cleanup-temporary-uploads
        {--batch=100 : Hoechstzahl der in einem Lauf behandelten Uploads}';

    protected $description = 'Löscht überfällige Originaluploads aus dem Kurzzeitbereich und weist die Löschung nach.';

    public function handle(CleanupExpiredUploads $cleanup): int
    {
        $batch = (int) $this->option('batch');

        $report = $cleanup($batch > 0 ? $batch : CleanupExpiredUploads::DEFAULT_BATCH_SIZE);

        $this->line($report->summary());

        if ($report->failed > 0) {
            $this->warn(sprintf(
                'Achtung: %d Löschungen sind fehlgeschlagen. Der Adminbereich führt das als kritischen '
                .'Datenschutzalarm. Wiederholung über smartabrechnen:retry-failed-deletions.',
                $report->failed
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
