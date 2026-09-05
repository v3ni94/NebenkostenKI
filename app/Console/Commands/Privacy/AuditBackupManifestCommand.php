<?php

declare(strict_types=1);

namespace App\Console\Commands\Privacy;

use App\Application\Privacy\AuditBackupManifest;
use App\Application\Privacy\BackupExclusionPolicy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Prüft ein Backup-Manifest gegen die verbindliche Ausschlussliste.
 *
 * Aufruf im Backupskript unmittelbar nach dem Erstellen des Archivs:
 *
 *     php artisan smartabrechnen:audit-backup-manifest /pfad/manifest.txt
 *
 * Der Befehl endet mit Fehlercode 1, wenn das Manifest einen Pfad enthält, der
 * nach Abschnitt 19 in keinem Backup liegen darf. Das Backupskript bricht dann
 * ab und verwirft das Archiv, statt es abzulegen.
 */
final class AuditBackupManifestCommand extends Command
{
    protected $signature = 'smartabrechnen:audit-backup-manifest
        {manifest : Pfad zur Manifestdatei mit einem Backup-Pfad je Zeile}
        {--regeln : Gibt zusätzlich die geprüften Ausschlussregeln aus}';

    protected $description = 'Prüft ein Backup-Manifest gegen die Ausschlussliste und endet bei einem Befund mit Fehlercode.';

    public function handle(AuditBackupManifest $audit): int
    {
        $pfad = (string) $this->argument('manifest');

        if ($this->option('regeln') === true) {
            $this->line('Geprüfte Ausschlussregeln:');

            foreach (BackupExclusionPolicy::ruleNames() as $regel) {
                $this->line('  - '.$regel);
            }

            $this->line('');
        }

        try {
            $bericht = $audit->fromFile($pfad);
        } catch (Throwable $fehler) {
            $this->error($fehler->getMessage());

            return self::FAILURE;
        }

        if ($bericht->isCompliant()) {
            $this->info($bericht->summary());

            return self::SUCCESS;
        }

        $this->error($bericht->summary());

        foreach ($bericht->violations as $befund) {
            $this->error(sprintf('  verboten: %s (Regel: %s)', $befund['path'], $befund['rule']));
        }

        $this->error(
            'Das Backup ist nicht konform. Es darf nicht abgelegt werden. Die Ausschlussliste im '
            .'Backupskript ist zu korrigieren und das Archiv neu zu erstellen.'
        );

        return self::FAILURE;
    }
}
