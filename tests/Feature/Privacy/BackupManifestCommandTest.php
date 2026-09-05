<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use Tests\TestCase;

/**
 * Ausfuehrbare Pruefung des Backup-Manifests (Masterprompt 19).
 *
 * Der Befehl muss bei einem verbotenen Pfad mit Fehlercode enden, damit das
 * Backupskript abbricht und das Archiv verwirft.
 */
final class BackupManifestCommandTest extends TestCase
{
    public function test_konformes_manifest_endet_erfolgreich(): void
    {
        $pfad = $this->manifest([
            '# Backup vom 01.09.2026',
            './artefakte/abrechnungen/final/01J8.pdf',
            './artefakte/rechnungen/01J9.pdf',
        ]);

        $this->artisan('smartabrechnen:audit-backup-manifest', ['manifest' => $pfad])
            ->expectsOutputToContain('bestanden')
            ->assertExitCode(0);

        @unlink($pfad);
    }

    public function test_verletzendes_manifest_endet_mit_fehlercode(): void
    {
        $pfad = $this->manifest([
            './artefakte/abrechnungen/final/01J8.pdf',
            './storage/app/temporary-uploads/01JC/original.pdf',
        ]);

        $this->artisan('smartabrechnen:audit-backup-manifest', ['manifest' => $pfad])
            ->expectsOutputToContain('fehlgeschlagen')
            ->expectsOutputToContain('Kurzzeitbereich mit Originaluploads')
            ->expectsOutputToContain('Das Backup ist nicht konform.')
            ->assertExitCode(1);

        @unlink($pfad);
    }

    public function test_regelausgabe_dokumentiert_den_pruefumfang(): void
    {
        $pfad = $this->manifest(['./artefakte/rechnungen/01J9.pdf']);

        $this->artisan('smartabrechnen:audit-backup-manifest', [
            'manifest' => $pfad,
            '--regeln' => true,
        ])
            ->expectsOutputToContain('Geprüfte Ausschlussregeln:')
            ->expectsOutputToContain('KI-Zwischendaten')
            ->assertExitCode(0);

        @unlink($pfad);
    }

    public function test_fehlendes_manifest_endet_mit_fehlercode(): void
    {
        $this->artisan('smartabrechnen:audit-backup-manifest', [
            'manifest' => '/nicht/vorhanden/manifest.txt',
        ])
            ->expectsOutputToContain('nicht lesbar')
            ->assertExitCode(1);
    }

    /**
     * @param  list<string>  $zeilen
     */
    private function manifest(array $zeilen): string
    {
        $pfad = tempnam(sys_get_temp_dir(), 'sa-manifest-');

        self::assertIsString($pfad);
        file_put_contents($pfad, implode("\n", $zeilen)."\n");

        return $pfad;
    }
}
