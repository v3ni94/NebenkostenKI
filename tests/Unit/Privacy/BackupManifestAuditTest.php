<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use App\Application\Privacy\AuditBackupManifest;
use App\Application\Privacy\BackupExclusionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Pruefung eines Backup-Manifests gegen die verbindliche Ausschlussliste
 * (Masterprompt 19).
 */
final class BackupManifestAuditTest extends TestCase
{
    public function test_konformes_manifest_besteht_die_pruefung(): void
    {
        $bericht = $this->audit()->fromContents(implode("\n", [
            '# Backup vom 01.09.2026',
            './artefakte/abrechnungen/final/01J8.pdf',
            './artefakte/rechnungen/01J9.pdf',
            './artefakte/pakete/01JA.zip',
            './artefakte/datenexporte/01JB.zip',
            'datenbank/smartabrechnen-2026-09-01.sql.gz.gpg',
            '',
        ]));

        self::assertTrue($bericht->isCompliant());
        self::assertSame(5, $bericht->inspectedPaths);
        self::assertStringContainsString('bestanden', $bericht->summary());
    }

    public function test_manifest_mit_kurzzeitbereich_wird_beanstandet(): void
    {
        $bericht = $this->audit()->fromContents(implode("\n", [
            './artefakte/abrechnungen/final/01J8.pdf',
            './storage/app/temporary-uploads/01JC/original.pdf',
        ]));

        self::assertFalse($bericht->isCompliant());
        self::assertCount(1, $bericht->violations);
        self::assertSame('Kurzzeitbereich mit Originaluploads', $bericht->violations[0]['rule']);
        self::assertStringContainsString('fehlgeschlagen', $bericht->summary());
    }

    public function test_manifest_mit_seitenbildern_wird_beanstandet(): void
    {
        $bericht = $this->audit()->fromPaths(['artefakte/seitenbilder/01JD-1.png']);

        self::assertFalse($bericht->isCompliant());
        self::assertSame(
            'Seitenbilder und Vorschaubilder der Quelldokumente',
            $bericht->violations[0]['rule']
        );
    }

    public function test_manifest_mit_ocr_datei_wird_beanstandet(): void
    {
        $bericht = $this->audit()->fromPaths(['auswertung/01JE.ocr.txt']);

        self::assertFalse($bericht->isCompliant());
        self::assertSame('Vollständige OCR-Dateien und Text-Layer', $bericht->violations[0]['rule']);
    }

    public function test_manifest_mit_ki_zwischendaten_wird_beanstandet(): void
    {
        $bericht = $this->audit()->fromPaths(['tmp/ai-raw/antwort-01JF.json']);

        self::assertFalse($bericht->isCompliant());
        self::assertSame('KI-Zwischendaten', $bericht->violations[0]['rule']);
    }

    public function test_manifest_mit_queue_payloads_wird_beanstandet(): void
    {
        $bericht = $this->audit()->fromPaths([
            'storage/framework/cache/data/ab/cd/ef',
            'export/queue-payloads/job-01JG.json',
        ]);

        self::assertFalse($bericht->isCompliant());
        self::assertCount(2, $bericht->violations);
        self::assertSame('Queue-Payloads und Framework-Caches', $bericht->violations[0]['rule']);
    }

    public function test_pruefung_laesst_sich_durch_schreibweise_nicht_aushebeln(): void
    {
        foreach ([
            'STORAGE/APP/TEMPORARY-UPLOADS/x.pdf',
            'storage\\app\\temporary-uploads\\x.pdf',
            '././storage/app//temporary-uploads/x.pdf',
        ] as $pfad) {
            self::assertNotNull(
                BackupExclusionPolicy::violatedRule($pfad),
                'Der Pfad '.$pfad.' wird nicht erkannt.'
            );
        }
    }

    public function test_kommentare_und_leerzeilen_werden_uebersprungen(): void
    {
        $bericht = $this->audit()->fromContents("# Kommentar\n\n   \nartefakte/final/01JH.pdf\n");

        self::assertSame(1, $bericht->inspectedPaths);
        self::assertTrue($bericht->isCompliant());
    }

    public function test_ausschlussliste_nennt_alle_fuenf_bereiche(): void
    {
        self::assertCount(5, BackupExclusionPolicy::ruleNames());
        self::assertCount(5, BackupExclusionPolicy::rules());
    }

    public function test_nicht_lesbares_manifest_wirft_eine_ausnahme(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->audit()->fromFile('/nicht/vorhanden/manifest.txt');
    }

    private function audit(): AuditBackupManifest
    {
        return new AuditBackupManifest;
    }
}
