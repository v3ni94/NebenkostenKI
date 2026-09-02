<?php

declare(strict_types=1);

namespace Tests\Feature\Upload;

use App\Enums\DocumentProcessingStatus;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Ai\Integration\DocumentPayloadFactory;
use App\Services\Storage\Crypto\TemporaryUploadKeyring;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Nachweis fuer Abschnitt 3.4 und 6.3 Schritt 1: Der Kurzzeitbereich ist ein
 * VERSCHLUESSELTER Quarantaenebereich.
 *
 * Geprueft wird nach jedem Schritt der Pipeline gegen ALLE Dateien im
 * Kurzzeitbereich, nicht nur gegen den erwarteten Pfad: Nirgends liegt der
 * Klartext eines Uploads.
 */
class EncryptedQuarantineTest extends TestCase
{
    use BuildsUploadWorld, RefreshDatabase;

    private const MARKER = 'STRENG-VERTRAULICHER-BELEG-9331';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpUploadWorld();

        config([
            'smartabrechnen.uploads.max_file_mb' => 25,
            'smartabrechnen.uploads.max_run_mb' => 250,
            'smartabrechnen.uploads.chunk_size_mb' => 1,
            'smartabrechnen.uploads.malware_scanner.driver' => 'disabled',
        ]);
    }

    public function test_nach_der_chunk_annahme_liegt_kein_klartext_im_kurzzeitbereich(): void
    {
        $inhalt = $this->markiertesPdf();

        $antwort = $this->starteUpload('unterlage.pdf', strlen($inhalt));
        $uploadId = (string) $antwort->json('upload_id');

        $this->sendeAbschnitt($uploadId, 0, $inhalt)->assertOk();

        $dateien = Storage::disk(TemporaryUploadStorage::DISK)->allFiles();

        $this->assertCount(1, $dateien, 'Der Abschnitt muss auf der Platte liegen.');
        $this->assertKeinKlartextImKurzzeitbereich();
    }

    public function test_nach_der_zusammensetzung_liegt_kein_klartext_und_die_datei_ist_byteidentisch_lesbar(): void
    {
        $inhalt = $this->markiertesPdf();
        $upload = $this->ladeDateiHoch($inhalt, 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        // Ohne KI-Schicht bleibt die Datei nach der Zusammensetzung liegen.
        $this->verarbeiteQueue();

        $storage = new TemporaryUploadStorage;
        $originalKey = $storage->originalKey($prefix);

        $this->assertTrue($storage->exists($originalKey));
        $this->assertKeinKlartextImKurzzeitbereich();
        $this->assertSame($inhalt, $storage->read($originalKey));
        $this->assertSame(strlen($inhalt), $storage->size($originalKey));

        $dokument = Document::query()->firstOrFail();

        $this->assertSame('application/pdf', $dokument->getAttribute('mime_type'));
        $this->assertSame(2, $dokument->getAttribute('page_count'));
        $this->assertNotNull($dokument->getAttribute('fingerprint_hmac'));
    }

    public function test_nach_dem_entpacken_liegen_die_eintraege_nur_verschluesselt(): void
    {
        $pdf = $this->markiertesPdf();

        $this->ladeDateiHoch(SampleFiles::zip([
            'bescheid.pdf' => $pdf,
            'foto.png' => SampleFiles::png(),
        ]), 'zip');

        $this->verarbeiteQueue();

        $this->assertSame(3, Document::query()->count());
        $this->assertKeinKlartextImKurzzeitbereich();

        $storage = new TemporaryUploadStorage;
        $gelesene = [];

        foreach (TemporaryUpload::query()->where('is_tombstone', false)->get() as $eintrag) {
            $prefix = (string) $eintrag->getAttribute('storage_key');
            $gelesene[] = $storage->read($storage->originalKey($prefix));
            $this->assertNotNull($eintrag->getAttribute('encryption_key_wrapped'));
        }

        $this->assertContains($pdf, $gelesene, 'Der entpackte Eintrag muss byteidentisch lesbar sein.');
        $this->assertContains(SampleFiles::png(), $gelesene);

        // Keine Arbeitskopie des Archivs ist liegen geblieben.
        foreach (Storage::disk(TemporaryUploadStorage::DISK)->allFiles() as $datei) {
            $this->assertStringNotContainsString('/arbeit/', $datei);
        }
    }

    public function test_fingerabdruck_ueber_den_klartext_ist_bei_zwei_uploads_derselben_datei_stabil(): void
    {
        $inhalt = SampleFiles::pdf(2);
        $storage = new TemporaryUploadStorage;

        $erster = $this->ladeDateiHoch($inhalt, 'pdf');
        $zweiter = $this->ladeDateiHoch($inhalt, 'pdf');

        $chiffratEins = (string) Storage::disk(TemporaryUploadStorage::DISK)
            ->get($storage->chunkKey((string) $erster->getAttribute('storage_key'), 0));
        $chiffratZwei = (string) Storage::disk(TemporaryUploadStorage::DISK)
            ->get($storage->chunkKey((string) $zweiter->getAttribute('storage_key'), 0));

        $this->assertNotSame($chiffratEins, $chiffratZwei, 'Die Chiffrate muessen sich unterscheiden.');

        $this->verarbeiteQueue();

        $dokumente = Document::query()->orderBy('sequence_number')->get();

        $this->assertCount(2, $dokumente);
        $this->assertNotNull($dokumente[0]->getAttribute('fingerprint_hmac'));
        $this->assertSame(
            $dokumente[0]->getAttribute('fingerprint_hmac'),
            $dokumente[1]->getAttribute('fingerprint_hmac'),
        );
        $this->assertSame($dokumente[0]->getKey(), $dokumente[1]->getAttribute('duplicate_of_document_id'));
    }

    public function test_die_ki_uebergabe_erhaelt_den_klartext(): void
    {
        $inhalt = $this->markiertesPdf();
        $upload = $this->ladeDateiHoch($inhalt, 'pdf');

        $this->verarbeiteQueue();

        $upload->refresh();
        $dokument = Document::query()->firstOrFail();

        $payload = (new DocumentPayloadFactory(new TemporaryUploadStorage))->forUpload($dokument, $upload);

        $this->assertNotNull($payload);
        $this->assertSame($inhalt, $payload->contents());
        $this->assertSame('application/pdf', $payload->mimeType);
        $this->assertKeinKlartextImKurzzeitbereich();
    }

    public function test_der_umhuellte_dateischluessel_steht_im_datensatz_und_wird_mit_der_loeschung_entfernt(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');

        $umhuellt = $upload->getAttribute('encryption_key_wrapped');

        $this->assertIsString($umhuellt);
        $this->assertMatchesRegularExpression('#^s1\.[A-Za-z0-9+/=]+$#', $umhuellt);
        $this->assertArrayNotHasKey('encryption_key_wrapped', $upload->toArray(), 'Der Schluessel darf nicht serialisiert werden.');

        $this->verarbeiteQueue();

        $upload->refresh();

        $this->assertTrue($upload->getAttribute('is_tombstone'));
        $this->assertNull($upload->getAttribute('storage_key'));
        $this->assertNull($upload->getAttribute('encryption_key_wrapped'));
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles());
    }

    public function test_manipuliertes_chiffrat_wird_in_der_pipeline_abgelehnt_und_hinterlaesst_keine_zieldatei(): void
    {
        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');
        $storage = new TemporaryUploadStorage;

        $pfad = $storage->absolutePath($storage->chunkKey($prefix, 0));
        $chiffrat = (string) file_get_contents($pfad);
        $chiffrat[70] = chr(ord($chiffrat[70]) ^ 0x01);
        file_put_contents($pfad, $chiffrat);

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertNull($dokument->getAttribute('fingerprint_hmac'));
        $this->assertNotContains(
            $dokument->getAttribute('processing_status'),
            [DocumentProcessingStatus::KLASSIFIZIERUNG, DocumentProcessingStatus::ABGESCHLOSSEN],
            'Ein manipulierter Abschnitt darf die Pruefkette nicht passieren.'
        );
        $this->assertFalse($storage->exists($storage->originalKey($prefix)));
    }

    public function test_wechsel_von_app_key_macht_laufende_uploads_unbrauchbar_statt_klartext_zu_liefern(): void
    {
        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32))]);
        TemporaryUploadKeyring::flushProcessCache();

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertNull($dokument->getAttribute('fingerprint_hmac'));
        $this->assertNotSame(DocumentProcessingStatus::ABGESCHLOSSEN, $dokument->getAttribute('processing_status'));
        $this->assertFalse((new TemporaryUploadStorage)->exists((new TemporaryUploadStorage)->originalKey($prefix)));
    }

    public function test_eine_30_mb_datei_wird_ohne_speicherlast_verarbeitet(): void
    {
        config(['smartabrechnen.uploads.max_file_mb' => 40]);

        $this->bindeErfolgreicheKiSchicht();

        $abschnittsgroesse = 1024 * 1024;
        $abschnitte = 30;
        $groesse = $abschnitte * $abschnittsgroesse;

        $antwort = $this->starteUpload('gross.pdf', $groesse);
        $antwort->assertCreated();

        $uploadId = (string) $antwort->json('upload_id');

        $this->assertSame($abschnitte, (int) $antwort->json('abschnitte'));
        $this->assertSame($abschnittsgroesse, (int) $antwort->json('abschnittsgroesse'));

        // Die Datei wird abschnittsweise erzeugt und nie als Ganzes gehalten,
        // damit die Speichermessung den Lauf und nicht den Test misst.
        for ($index = 0; $index < $abschnitte; $index++) {
            $this->sendeAbschnitt($uploadId, $index, $this->grosserAbschnitt($index, $abschnitte, $abschnittsgroesse))->assertOk();
        }

        $this->schliesseUploadAb($uploadId, 'pdf')->assertAccepted();

        gc_collect_cycles();
        memory_reset_peak_usage();
        $vorher = memory_get_peak_usage();

        $this->verarbeiteQueue();

        $nachher = memory_get_peak_usage();
        $zuwachs = $nachher - $vorher;

        $this->assertLessThan(
            $groesse / 2,
            $zuwachs,
            sprintf('Der Speicherzuwachs (%d Byte) muss deutlich unter der Dateigroesse (%d Byte) liegen.', $zuwachs, $groesse)
        );

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DocumentProcessingStatus::ABGESCHLOSSEN, $dokument->getAttribute('processing_status'));
        $this->assertSame($groesse, $dokument->getAttribute('original_byte_size'));
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles());

        fwrite(STDERR, sprintf(
            "\nSpeichermessung 30-MB-Datei: Zuwachs des Spitzenwerts %.1f MB bei %.1f MB Dateigroesse.\n",
            $zuwachs / 1048576,
            $groesse / 1048576,
        ));
    }

    /**
     * Prueft ALLE Dateien im Kurzzeitbereich, nicht nur den erwarteten Pfad.
     */
    private function assertKeinKlartextImKurzzeitbereich(): void
    {
        $disk = Storage::disk(TemporaryUploadStorage::DISK);
        $dateien = $disk->allFiles();

        $this->assertNotSame([], $dateien, 'Die Pruefung ist nur aussagekraeftig, wenn Dateien vorhanden sind.');

        foreach ($dateien as $datei) {
            $inhalt = (string) $disk->get($datei);

            $this->assertStringNotContainsString(self::MARKER, $inhalt, 'Klartext in '.$datei);
            $this->assertStringNotContainsString('%PDF-', $inhalt, 'PDF-Kopf in '.$datei);
            $this->assertStringNotContainsString("\x89PNG", $inhalt, 'PNG-Kopf in '.$datei);
            $this->assertStringStartsWith('SAQ1', $inhalt, 'Kein Chiffrat-Vorspann in '.$datei);
        }
    }

    private function markiertesPdf(): string
    {
        return str_replace('trailer', '% '.self::MARKER."\ntrailer", SampleFiles::pdf(2));
    }

    /**
     * Abschnitt einer strukturell gueltigen, grossen PDF-Datei.
     */
    private function grosserAbschnitt(int $index, int $abschnitte, int $groesse): string
    {
        $kopf = $index === 0 ? "%PDF-1.4\n% grosse Testdatei\n" : '';
        $ende = $index === $abschnitte - 1 ? "\ntrailer<</Root 1 0 R>>\nstartxref\n0\n%%EOF\n" : '';

        return $kopf.str_repeat(chr(65 + ($index % 26)), $groesse - strlen($kopf) - strlen($ende)).$ende;
    }
}
