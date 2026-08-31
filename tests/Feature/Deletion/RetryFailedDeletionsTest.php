<?php

declare(strict_types=1);

namespace Tests\Feature\Deletion;

use App\Application\Documents\RetryFailedDeletions;
use App\Enums\AiProvider;
use App\Enums\DeletionStatus;
use App\Models\Document;
use App\Models\SourceDeletionEvent;
use App\Models\TemporaryUpload;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\Feature\Upload\Concerns\ProviderLoeschProtokoll;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Prueft die Wiederholung fehlgeschlagener Loeschungen (Abschnitt 19).
 *
 * Eine fehlgeschlagene Loeschung ist ein kritischer Datenschutzalarm und wird
 * im Adminbereich angezeigt, bis sie erledigt ist.
 */
class RetryFailedDeletionsTest extends TestCase
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

    public function test_fehlgeschlagene_providerloeschung_wird_wiederholt_und_erledigt(): void
    {
        $this->bindeErfolgreicheKiSchicht();
        $this->bindeProviderLoescher(erfolgreich: false);

        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');

        $upload->forceFill([
            'provider' => AiProvider::OPENAI,
            'provider_file_id' => 'file-zuerst-nicht-loeschbar',
        ])->save();

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DeletionStatus::FEHLGESCHLAGEN, $dokument->getAttribute('deletion_status'));
        $this->assertSame(1, (new RetryFailedDeletions($this->app->make(
            \App\Application\Documents\DeleteOriginalSources::class
        )))->openAlertCount());

        // Der Provider ist wieder erreichbar.
        $this->bindeProviderLoescher(erfolgreich: true);

        $this->artisan('smartabrechnen:retry-failed-deletions')->assertSuccessful();

        $dokument->refresh();
        $upload->refresh();

        $this->assertSame(DeletionStatus::ERFOLGREICH, $dokument->getAttribute('deletion_status'));
        $this->assertTrue($upload->getAttribute('is_tombstone'));
        $this->assertNull($upload->getAttribute('provider_file_id'));
    }

    public function test_wiederholung_erzeugt_einen_zusaetzlichen_nachweis_mit_hoeherem_versuch(): void
    {
        $this->bindeErfolgreicheKiSchicht();
        $this->bindeProviderLoescher(erfolgreich: false);

        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');

        $upload->forceFill([
            'provider' => AiProvider::OPENAI,
            'provider_file_id' => 'file-dauerhaft-gestoert',
        ])->save();

        $this->verarbeiteQueue();

        $this->artisan('smartabrechnen:retry-failed-deletions')->assertFailed();

        $versuche = SourceDeletionEvent::query()->orderBy('attempt')->pluck('attempt')->all();

        $this->assertSame([1, 2], array_map('intval', $versuche));
    }

    public function test_dauerhafte_fehler_werden_als_ueberfaellig_gefuehrt(): void
    {
        $this->bindeErfolgreicheKiSchicht();
        $this->bindeProviderLoescher(erfolgreich: false);

        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');

        $upload->forceFill([
            'provider' => AiProvider::OPENAI,
            'provider_file_id' => 'file-dauerhaft-gestoert',
        ])->save();

        $this->verarbeiteQueue();

        // Zwei weitere Versuche fuehren in den Zustand UEBERFAELLIG.
        $this->artisan('smartabrechnen:retry-failed-deletions')->assertFailed();
        $this->artisan('smartabrechnen:retry-failed-deletions')->assertFailed();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DeletionStatus::UEBERFAELLIG, $dokument->getAttribute('deletion_status'));
        $this->assertTrue($dokument->getAttribute('deletion_status')->isPrivacyAlert());
    }

    public function test_wiederholung_ist_ohne_offene_faelle_folgenlos(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $this->verarbeiteQueue();

        $ereignisse = SourceDeletionEvent::query()->count();

        $this->artisan('smartabrechnen:retry-failed-deletions')->assertSuccessful();

        $this->assertSame($ereignisse, SourceDeletionEvent::query()->count());
    }

    public function test_offene_alarme_sind_ueber_die_monitorabfrage_auslesbar(): void
    {
        $this->bindeErfolgreicheKiSchicht();
        $this->bindeProviderLoescher(erfolgreich: false);

        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');

        $upload->forceFill([
            'provider' => AiProvider::OPENAI,
            'provider_file_id' => 'file-gestoert',
        ])->save();

        $this->verarbeiteQueue();

        $this->assertSame(1, Document::query()->deletionPending()->count());
        $this->assertSame(1, SourceDeletionEvent::query()->unresolved()->count());
    }

    public function test_verwaistes_kurzzeitdatensatz_wird_bereinigt(): void
    {
        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        $dokument = Document::query()->firstOrFail();

        $dokument->forceFill(['deletion_status' => DeletionStatus::FEHLGESCHLAGEN])->save();

        $this->artisan('smartabrechnen:retry-failed-deletions')->assertSuccessful();

        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
        $this->assertSame(1, TemporaryUpload::query()->where('is_tombstone', true)->count());
    }
}
