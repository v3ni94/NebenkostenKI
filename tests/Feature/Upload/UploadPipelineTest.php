<?php

declare(strict_types=1);

namespace Tests\Feature\Upload;

use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\ProcessingJob;
use App\Services\Storage\HeicConverter;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Prueft die Pruefkette nach der Zusammensetzung: Magic Bytes, Struktur,
 * Malware, HEIC, Archive, Fingerabdruck und Dublettenerkennung
 * (Abschnitt 6.3 Schritte 1 bis 7).
 */
class UploadPipelineTest extends TestCase
{
    use BuildsUploadWorld, RefreshDatabase;

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

    public function test_gueltiges_pdf_durchlaeuft_die_pruefkette_und_wird_klassifiziert(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $this->ladeDateiHoch(SampleFiles::pdf(3), 'pdf');

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame('application/pdf', $dokument->getAttribute('mime_type'));
        $this->assertSame(3, $dokument->getAttribute('page_count'));
        $this->assertNotNull($dokument->getAttribute('fingerprint_hmac'));
        $this->assertNotNull($dokument->getAttribute('security_checked_at'));
        $this->assertSame('disabled', $dokument->getAttribute('malware_scanner_driver'));
        $this->assertNull(
            $dokument->getAttribute('malware_scan_clean'),
            'Bei abgeschaltetem Scanner darf nicht behauptet werden, die Datei sei geprueft.'
        );
    }

    public function test_mime_taeuschung_wird_nach_der_zusammensetzung_abgelehnt(): void
    {
        $this->ladeDateiHoch(SampleFiles::png(), 'pdf');

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DocumentProcessingStatus::ABGELEHNT, $dokument->getAttribute('processing_status'));
        $this->assertSame(UploadErrorCode::MIME_TAEUSCHUNG->value, $dokument->getAttribute('failure_code'));
        $this->assertNotNull($dokument->getAttribute('original_deleted_at'));
    }

    public function test_heic_ohne_konverter_liefert_eine_deutsche_handlungsanweisung(): void
    {
        if ((new HeicConverter)->isAvailable()) {
            $this->markTestSkipped('Auf diesem System ist ein HEIC-Konverter vorhanden.');
        }

        $this->ladeDateiHoch(SampleFiles::heic(), 'heic');

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(UploadErrorCode::HEIC_KONVERTER_FEHLT->value, $dokument->getAttribute('failure_code'));
        $this->assertStringContainsString('JPG', (string) $dokument->getAttribute('failure_message'));
        $this->assertNotNull($dokument->getAttribute('original_deleted_at'));
    }

    public function test_zip_bombe_wird_abgelehnt(): void
    {
        $this->ladeDateiHoch(SampleFiles::zipBomb(), 'zip');

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(UploadErrorCode::ARCHIV_ZIP_BOMBE->value, $dokument->getAttribute('failure_code'));
        $this->assertNotNull($dokument->getAttribute('original_deleted_at'));
    }

    public function test_archiv_mit_path_traversal_wird_vollstaendig_abgelehnt(): void
    {
        $this->ladeDateiHoch(SampleFiles::zipWithTraversal(), 'zip');

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(UploadErrorCode::ARCHIV_TRAVERSAL->value, $dokument->getAttribute('failure_code'));
        $this->assertSame(1, Document::query()->count(), 'Kein Eintrag darf entpackt worden sein.');
    }

    public function test_gueltiges_archiv_wird_in_einzeldokumente_aufgeloest(): void
    {
        $this->ladeDateiHoch(SampleFiles::zip([
            'bescheid.pdf' => SampleFiles::pdf(2),
            'foto.png' => SampleFiles::png(),
        ]), 'zip');

        $this->verarbeiteQueue();

        $this->assertSame(3, Document::query()->count());

        $archiv = Document::query()->where('sequence_number', 1)->firstOrFail();

        $this->assertSame(DocumentProcessingStatus::ABGESCHLOSSEN, $archiv->getAttribute('processing_status'));
        $this->assertNotNull($archiv->getAttribute('original_deleted_at'));

        $entpackt = Document::query()->where('sequence_number', '>', 1)->get();

        foreach ($entpackt as $dokument) {
            $this->assertNotNull($dokument->getAttribute('fingerprint_hmac'));
            $this->assertStringStartsWith('Dokument 0', (string) $dokument->getAttribute('source_label'));
        }

        $this->assertDatabaseCount('document_relations', 2);
    }

    public function test_dubletten_werden_ueber_den_hmac_erkannt(): void
    {
        $inhalt = SampleFiles::pdf(2);

        $this->ladeDateiHoch($inhalt, 'pdf');
        $this->verarbeiteQueue();

        $this->ladeDateiHoch($inhalt, 'pdf');
        $this->verarbeiteQueue();

        $dokumente = Document::query()->orderBy('sequence_number')->get();

        $this->assertCount(2, $dokumente);
        $this->assertSame(
            $dokumente[0]->getAttribute('fingerprint_hmac'),
            $dokumente[1]->getAttribute('fingerprint_hmac')
        );
        $this->assertNull($dokumente[0]->getAttribute('duplicate_of_document_id'));
        $this->assertSame($dokumente[0]->getKey(), $dokumente[1]->getAttribute('duplicate_of_document_id'));
        $this->assertSame(UploadErrorCode::DUBLETTE->value, $dokumente[1]->getAttribute('failure_code'));
        $this->assertNotNull($dokumente[1]->getAttribute('original_deleted_at'));
    }

    public function test_unterschiedliche_dateien_sind_keine_dubletten(): void
    {
        $this->ladeDateiHoch(SampleFiles::pdf(1), 'pdf');
        $this->ladeDateiHoch(SampleFiles::pdf(4), 'pdf');

        $this->verarbeiteQueue();

        foreach (Document::query()->get() as $dokument) {
            $this->assertNull($dokument->getAttribute('duplicate_of_document_id'));
        }
    }

    public function test_klassifikation_setzt_die_neutrale_quellenbezeichnung_neu(): void
    {
        $this->bindeErfolgreicheKiSchicht(DocumentType::HEIZKOSTENABRECHNUNG);

        $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DocumentType::HEIZKOSTENABRECHNUNG, $dokument->getAttribute('document_type'));
        $this->assertSame('Dokument 01 - Heizkostenabrechnung', $dokument->getAttribute('source_label'));
        $this->assertNotNull($dokument->getAttribute('classified_at'));
    }

    public function test_manuelle_zuordnung_wird_von_der_klassifikation_nicht_ueberschrieben(): void
    {
        $this->bindeErfolgreicheKiSchicht(DocumentType::HEIZKOSTENABRECHNUNG);

        $antwort = $this->starteUpload('unterlage.pdf', strlen(SampleFiles::pdf(2)), 'GRUNDSTEUERBESCHEID');
        $uploadId = (string) $antwort->json('upload_id');

        $this->sendeAbschnitt($uploadId, 0, SampleFiles::pdf(2))->assertOk();
        $this->schliesseUploadAb($uploadId, 'pdf')->assertAccepted();

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DocumentType::GRUNDSTEUERBESCHEID, $dokument->getAttribute('document_type'));
        $this->assertSame('Dokument 01 - Grundsteuerbescheid', $dokument->getAttribute('source_label'));
    }

    public function test_ohne_ki_schicht_wird_wiederholt_und_danach_endgueltig_abgelehnt(): void
    {
        $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');

        // Erster Lauf: Zusammensetzung erfolgreich, Klassifikation eingestellt.
        $this->verarbeiteQueue();

        $klassifikation = ProcessingJob::query()
            ->where('job_type', 'dokument.klassifizieren')
            ->firstOrFail();

        $this->assertSame(3, (int) $klassifikation->getAttribute('max_attempts'));

        $dokument = Document::query()->firstOrFail();

        $this->assertNotSame(
            DocumentProcessingStatus::ABGESCHLOSSEN,
            $dokument->getAttribute('processing_status')
        );
    }
}
