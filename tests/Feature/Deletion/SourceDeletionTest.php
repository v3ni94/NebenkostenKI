<?php

declare(strict_types=1);

namespace Tests\Feature\Deletion;

use App\Enums\AiProvider;
use App\Enums\DeletionStatus;
use App\Enums\DocumentProcessingStatus;
use App\Models\Document;
use App\Models\SourceDeletionEvent;
use App\Models\TemporaryUpload;
use App\Services\Storage\TemporaryFileKind;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\Feature\Upload\Concerns\ProviderLoeschProtokoll;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Nachweise aus Abschnitt 23.3 und 24.
 *
 * - Ein erfolgreicher Extraktionslauf loescht Originaldatei, Seitenbilder,
 *   vollstaendigen OCR-Text und Providerdatei.
 * - Ein endgueltig fehlgeschlagener Lauf loescht dieselben Quelldaten ebenfalls.
 * - Jede Loeschung erzeugt einen datensparsamen Nachweis.
 */
class SourceDeletionTest extends TestCase
{
    use BuildsUploadWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpUploadWorld();

        ProviderLoeschProtokoll::zuruecksetzen();

        config([
            'smartabrechnen.uploads.max_file_mb' => 25,
            'smartabrechnen.uploads.max_run_mb' => 250,
            'smartabrechnen.uploads.chunk_size_mb' => 1,
        ]);
    }

    public function test_erfolgreicher_lauf_loescht_original_seitenbilder_ocr_text_und_providerdatei(): void
    {
        $this->bindeErfolgreicheKiSchicht();
        $this->bindeProviderLoescher();

        $upload = $this->ladeDateiHoch(SampleFiles::pdf(3), 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        // Die auswertende Schicht legt waehrend der Verarbeitung Ableitungen an.
        $storage = new TemporaryUploadStorage;
        $storage->putDerivative($prefix, TemporaryFileKind::SEITENBILD, 'seite-1.png', SampleFiles::png());
        $storage->putDerivative($prefix, TemporaryFileKind::SEITENBILD, 'seite-2.png', SampleFiles::png());
        $storage->putDerivative($prefix, TemporaryFileKind::KONVERTIERUNG, 'konvertiert.jpg', SampleFiles::jpeg());
        $storage->putDerivative($prefix, TemporaryFileKind::OCR_TEXT, 'volltext.txt', 'Vollstaendiger OCR-Text');

        $upload->forceFill([
            'provider' => AiProvider::OPENAI,
            'provider_file_id' => 'file-testfall-0815',
        ])->save();

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DocumentProcessingStatus::ABGESCHLOSSEN, $dokument->getAttribute('processing_status'));
        $this->assertNotNull($dokument->getAttribute('extracted_at'));

        // 1. Originaldatei, Seitenbilder, Konvertierung und OCR-Text sind fort.
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
        $this->assertSame(0, $storage->countFiles($prefix));

        // 2. Die Providerdatei wurde ueber die Loeschschnittstelle entfernt.
        $this->assertSame(['file-testfall-0815'], ProviderLoeschProtokoll::$aufrufe);

        // 3. Der Kurzzeitdatensatz ist ein inhaltsloser Tombstone.
        $upload->refresh();

        $this->assertTrue($upload->getAttribute('is_tombstone'));
        $this->assertNull($upload->getAttribute('storage_key'));
        $this->assertNull($upload->getAttribute('provider_file_id'));
        $this->assertNotNull($upload->getAttribute('deleted_at'));
        $this->assertSame(DeletionStatus::ERFOLGREICH, $upload->getAttribute('provider_deletion_status'));

        // 4. Das Dokument fuehrt den Loeschnachweis.
        $this->assertNotNull($dokument->getAttribute('original_deleted_at'));
        $this->assertSame(DeletionStatus::ERFOLGREICH, $dokument->getAttribute('deletion_status'));
    }

    public function test_endgueltig_fehlgeschlagener_lauf_loescht_dieselben_quelldaten(): void
    {
        $this->bindeScheiterndeKiSchicht();
        $this->bindeProviderLoescher();

        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        $storage = new TemporaryUploadStorage;
        $storage->putDerivative($prefix, TemporaryFileKind::SEITENBILD, 'seite-1.png', SampleFiles::png());
        $storage->putDerivative($prefix, TemporaryFileKind::OCR_TEXT, 'volltext.txt', 'Vollstaendiger OCR-Text');

        $upload->forceFill([
            'provider' => AiProvider::ANTHROPIC,
            'provider_file_id' => 'file-fehlerfall-4711',
        ])->save();

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DocumentProcessingStatus::FEHLGESCHLAGEN, $dokument->getAttribute('processing_status'));
        $this->assertNotNull($dokument->getAttribute('failure_code'));

        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
        $this->assertSame(['file-fehlerfall-4711'], ProviderLoeschProtokoll::$aufrufe);

        $upload->refresh();

        $this->assertTrue($upload->getAttribute('is_tombstone'));
        $this->assertNull($upload->getAttribute('storage_key'));
        $this->assertNotNull($dokument->getAttribute('original_deleted_at'));
        $this->assertSame(DeletionStatus::ERFOLGREICH, $dokument->getAttribute('deletion_status'));
    }

    public function test_jede_loeschung_erzeugt_einen_datensparsamen_nachweis(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        $this->verarbeiteQueue();

        $ereignis = SourceDeletionEvent::query()->firstOrFail();

        $this->assertSame(
            Document::query()->firstOrFail()->getKey(),
            $ereignis->getAttribute('document_id')
        );
        $this->assertSame(DeletionStatus::ERFOLGREICH, $ereignis->getAttribute('local_deletion_status'));
        $this->assertNotNull($ereignis->getAttribute('occurred_at'));
        $this->assertSame(1, $ereignis->getAttribute('attempt'));
        $this->assertNull($ereignis->getAttribute('error_code'));

        // Kein Dateiinhalt, kein Dateiname, kein Storage-Key im Nachweis.
        $gespeichert = json_encode($ereignis->getAttributes(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($prefix, $gespeichert);
        $this->assertStringNotContainsString('%PDF', $gespeichert);
        $this->assertStringNotContainsString('quarantaene', $gespeichert);
    }

    public function test_loeschnachweis_ueberlebt_die_entfernung_des_dokuments(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $this->verarbeiteQueue();

        $dokumentId = Document::query()->firstOrFail()->getKey();

        Document::query()->whereKey($dokumentId)->delete();

        $ereignis = SourceDeletionEvent::query()->firstOrFail();

        $this->assertSame($dokumentId, $ereignis->getAttribute('document_id'));
    }

    public function test_fehlgeschlagene_providerloeschung_haelt_die_lokale_loeschung_nicht_auf(): void
    {
        $this->bindeErfolgreicheKiSchicht();
        $this->bindeProviderLoescher(erfolgreich: false);

        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        $upload->forceFill([
            'provider' => AiProvider::OPENAI,
            'provider_file_id' => 'file-nicht-loeschbar',
        ])->save();

        $this->verarbeiteQueue();

        // Lokal ist alles fort, obwohl der Provider nicht erreichbar war.
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DeletionStatus::FEHLGESCHLAGEN, $dokument->getAttribute('deletion_status'));

        $ereignis = SourceDeletionEvent::query()->firstOrFail();

        $this->assertSame(DeletionStatus::ERFOLGREICH, $ereignis->getAttribute('local_deletion_status'));
        $this->assertSame(DeletionStatus::FEHLGESCHLAGEN, $ereignis->getAttribute('provider_deletion_status'));
        $this->assertNotNull($ereignis->getAttribute('error_code'));
    }

    public function test_zweiter_loeschlauf_aendert_nichts(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $this->verarbeiteQueue();

        $ereignisse = SourceDeletionEvent::query()->count();

        $this->verarbeiteQueue();

        $this->assertSame($ereignisse, SourceDeletionEvent::query()->count());
        $this->assertSame(1, TemporaryUpload::query()->count());
    }

    public function test_ohne_providerdatei_wird_keine_providerloeschung_behauptet(): void
    {
        $this->bindeErfolgreicheKiSchicht();
        $this->bindeProviderLoescher();

        $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $this->verarbeiteQueue();

        $this->assertSame([], ProviderLoeschProtokoll::$aufrufe);

        $ereignis = SourceDeletionEvent::query()->firstOrFail();

        $this->assertSame(
            DeletionStatus::NICHT_ERFORDERLICH,
            $ereignis->getAttribute('provider_deletion_status')
        );
    }
}
