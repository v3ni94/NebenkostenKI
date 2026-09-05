<?php

declare(strict_types=1);

namespace Tests\Feature\Deletion;

use App\Enums\DeletionStatus;
use App\Enums\DocumentProcessingStatus;
use App\Enums\ProcessingJobStatus;
use App\Models\Document;
use App\Models\ProcessingJob;
use App\Models\SourceDeletionEvent;
use App\Models\TemporaryUpload;
use App\Services\Storage\TemporaryFileKind;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Nachweis aus Abschnitt 23.3: Der TTL-Cleanup entfernt haengen gebliebene
 * Uploads und ist idempotent.
 *
 * Der Lauf ist bewusst unabhaengig von Worker, KI-Provider und Browsersitzung.
 */
class TtlCleanupTest extends TestCase
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
            'smartabrechnen.retention.temp_upload_ttl_minutes' => 120,
        ]);
    }

    public function test_ttl_beginnt_mit_dem_ersten_abschnitt(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $antwort = $this->starteUpload('unterlage.pdf', 2048);
        $uploadId = (string) $antwort->json('upload_id');

        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 30, 0));

        $this->sendeAbschnitt($uploadId, 0, str_repeat('A', 2048))->assertOk();

        $upload = TemporaryUpload::query()->findOrFail($uploadId);

        $this->assertSame('2026-05-01 10:30:00', $upload->getAttribute('first_chunk_at')->toDateTimeString());
        $this->assertSame('2026-05-01 12:30:00', $upload->getAttribute('expires_at')->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_cleanup_entfernt_haengen_gebliebene_uploads(): void
    {
        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        // Die Verarbeitung bleibt haengen: der Teiljob wird nie ausgefuehrt.
        $storage = new TemporaryUploadStorage;
        $storage->putDerivative($prefix, TemporaryFileKind::SEITENBILD, 'seite-1.png', SampleFiles::png());
        $storage->putDerivative($prefix, TemporaryFileKind::OCR_TEXT, 'volltext.txt', 'OCR-Volltext');

        $this->assertNotSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));

        Carbon::setTestNow(Carbon::now()->addMinutes(121));

        $this->artisan('smartabrechnen:cleanup-temporary-uploads')->assertSuccessful();

        Carbon::setTestNow();

        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));

        $upload->refresh();

        $this->assertTrue($upload->getAttribute('is_tombstone'));
        $this->assertNull($upload->getAttribute('storage_key'));

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DocumentProcessingStatus::ABGEBROCHEN, $dokument->getAttribute('processing_status'));
        $this->assertSame(UploadErrorCode::TTL_ABGELAUFEN->value, $dokument->getAttribute('failure_code'));
        $this->assertSame(DeletionStatus::ERFOLGREICH, $dokument->getAttribute('deletion_status'));
        $this->assertNotNull($dokument->getAttribute('original_deleted_at'));
    }

    public function test_cleanup_ist_idempotent(): void
    {
        $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');

        Carbon::setTestNow(Carbon::now()->addMinutes(121));

        $this->artisan('smartabrechnen:cleanup-temporary-uploads')->assertSuccessful();

        $ereignisse = SourceDeletionEvent::query()->count();
        $dokument = Document::query()->firstOrFail()->getAttributes();

        // Zweiter Lauf: es darf sich nichts mehr aendern.
        $this->artisan('smartabrechnen:cleanup-temporary-uploads')->assertSuccessful();

        Carbon::setTestNow();

        $this->assertSame($ereignisse, SourceDeletionEvent::query()->count());
        $this->assertSame($dokument, Document::query()->firstOrFail()->getAttributes());
        $this->assertSame(1, TemporaryUpload::query()->count());
    }

    public function test_cleanup_bricht_offene_teiljobs_ab(): void
    {
        $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');

        $this->assertSame(
            1,
            ProcessingJob::query()->where('status', ProcessingJobStatus::BEREIT->value)->count()
        );

        Carbon::setTestNow(Carbon::now()->addMinutes(121));

        $this->artisan('smartabrechnen:cleanup-temporary-uploads')->assertSuccessful();

        Carbon::setTestNow();

        $job = ProcessingJob::query()->firstOrFail();

        $this->assertSame(ProcessingJobStatus::ABGEBROCHEN, $job->getAttribute('status'));
        $this->assertSame(UploadErrorCode::TTL_ABGELAUFEN->value, $job->getAttribute('error_code'));
    }

    public function test_cleanup_laesst_noch_gueltige_uploads_unberuehrt(): void
    {
        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        $this->artisan('smartabrechnen:cleanup-temporary-uploads')->assertSuccessful();

        $this->assertNotSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));

        $upload->refresh();

        $this->assertFalse($upload->getAttribute('is_tombstone'));
    }

    public function test_cleanup_laeuft_auch_ohne_worker_und_ohne_ki_schicht(): void
    {
        // Es wird bewusst keine KI-Schicht gebunden und kein Queue-Lauf
        // ausgefuehrt. Der Cleanup ist davon unabhaengig.
        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        Carbon::setTestNow(Carbon::now()->addMinutes(121));

        $this->artisan('smartabrechnen:cleanup-temporary-uploads')->assertSuccessful();

        Carbon::setTestNow();

        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
        $this->assertSame(1, SourceDeletionEvent::query()->count());
    }

    public function test_cleanup_verarbeitet_stapelweise(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->ladeDateiHoch(SampleFiles::pdf($i + 1), 'pdf');
        }

        Carbon::setTestNow(Carbon::now()->addMinutes(121));

        $this->artisan('smartabrechnen:cleanup-temporary-uploads', ['--batch' => 2])->assertSuccessful();

        $this->assertSame(2, TemporaryUpload::query()->where('is_tombstone', true)->count());

        $this->artisan('smartabrechnen:cleanup-temporary-uploads', ['--batch' => 2])->assertSuccessful();

        Carbon::setTestNow();

        $this->assertSame(3, TemporaryUpload::query()->where('is_tombstone', true)->count());
    }

    public function test_cleanup_entfernt_verwaiste_chiffrate_ohne_datensatz_erst_nach_der_hoechst_ttl(): void
    {
        $storage = new TemporaryUploadStorage;

        // Verwaist: Datei ohne Datensatz, etwa nach einem Prozessabbruch
        // zwischen Dateischreiben und Speichern des Datensatzes.
        $verwaist = $storage->newPrefix();
        $storage->put($storage->originalKey($verwaist), SampleFiles::pdf());

        // Regulaer: gueltiger Upload mit Datensatz.
        $gueltig = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $gueltigPrefix = (string) $gueltig->getAttribute('storage_key');

        $this->artisan('smartabrechnen:cleanup-temporary-uploads')->assertSuccessful();

        $this->assertNotSame(
            [],
            Storage::disk(TemporaryUploadStorage::DISK)->allFiles($verwaist),
            'Ein frisch verwaistes Verzeichnis darf nicht sofort entfernt werden.'
        );

        Carbon::setTestNow(Carbon::now()->addMinutes(121));

        $this->artisan('smartabrechnen:cleanup-temporary-uploads')->assertSuccessful();

        Carbon::setTestNow();

        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($verwaist));
        $this->assertNotContains($verwaist, $storage->allPrefixes());

        // Der gueltige Upload ist mit seinem Datensatz ueber die TTL gelaufen
        // und regulaer geloescht worden; die Verzeichnisse sind leer.
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($gueltigPrefix));
        $this->assertSame(1, TemporaryUpload::query()->where('is_tombstone', true)->count());
    }

    public function test_ttl_ist_hart_auf_120_minuten_begrenzt(): void
    {
        config(['smartabrechnen.retention.temp_upload_ttl_minutes' => 9999]);

        Carbon::setTestNow(Carbon::create(2026, 5, 1, 10, 0, 0));

        $antwort = $this->starteUpload('unterlage.pdf', 1024);
        $uploadId = (string) $antwort->json('upload_id');

        $this->sendeAbschnitt($uploadId, 0, str_repeat('A', 1024))->assertOk();

        $upload = TemporaryUpload::query()->findOrFail($uploadId);

        $this->assertSame('2026-05-01 12:00:00', $upload->getAttribute('expires_at')->toDateTimeString());

        Carbon::setTestNow();
    }
}
