<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Application\Privacy\Dto\ManifestAuditReport;
use RuntimeException;

/**
 * Prüft ein Backup-Manifest gegen die verbindliche Ausschlussliste.
 *
 * Das Manifest ist eine einfache Textdatei mit einem Pfad je Zeile. Sie wird
 * beim Erstellen des Backups mitgeschrieben, zum Beispiel über
 * "tar --verbose --create ... > manifest.txt" beziehungsweise
 * "tar --list --file backup.tar.gz > manifest.txt" für ein bereits erzeugtes
 * Archiv. Leerzeilen und Zeilen, die mit # beginnen, werden übersprungen.
 *
 * Der Nachweis ist bewusst maschinell: Eine Ausschlussliste in einer Anleitung
 * ist nicht überprüfbar, ein geprüftes Manifest schon. Enthält das Manifest
 * einen verbotenen Pfad, endet der Lauf mit Fehlercode und das Backup ist als
 * nicht konform zu behandeln.
 */
final class AuditBackupManifest
{
    /**
     * @throws RuntimeException wenn die Manifestdatei nicht lesbar ist
     */
    public function fromFile(string $path): ManifestAuditReport
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(sprintf('Das Backup-Manifest "%s" ist nicht lesbar.', $path));
        }

        $inhalt = file_get_contents($path);

        if ($inhalt === false) {
            throw new RuntimeException(sprintf('Das Backup-Manifest "%s" konnte nicht gelesen werden.', $path));
        }

        return $this->fromContents($inhalt);
    }

    public function fromContents(string $contents): ManifestAuditReport
    {
        $zeilen = preg_split('/\R/', $contents);

        return $this->fromPaths(is_array($zeilen) ? $zeilen : []);
    }

    /**
     * @param  array<int, string>  $paths
     */
    public function fromPaths(array $paths): ManifestAuditReport
    {
        $geprueft = 0;
        $befunde = [];

        foreach ($paths as $pfad) {
            $wert = trim($pfad);

            if ($wert === '' || str_starts_with($wert, '#')) {
                continue;
            }

            $geprueft++;

            $regel = BackupExclusionPolicy::violatedRule($wert);

            if ($regel !== null) {
                $befunde[] = ['path' => $wert, 'rule' => $regel];
            }
        }

        return new ManifestAuditReport($geprueft, $befunde);
    }
}
