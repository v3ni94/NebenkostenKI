<?php

declare(strict_types=1);

namespace Tests\Feature\Upload;

use App\Enums\DocumentProcessingStatus;
use App\Enums\ProcessingJobStatus;
use App\Jobs\DocumentJobRegistry;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\ProcessingJob;
use App\Services\Queue\DatabaseJobQueue;
use App\Services\Queue\QueueSliceReport;
use App\Services\Queue\QueueSliceRunner;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Prueft die Wiederaufnahme nach einem Worker-Abbruch mitten im Job
 * (ADR-006, Profil A ohne dauerhaften Worker).
 *
 * Ein Cron-Lauf kann jederzeit enden, etwa durch die Prozesszeitgrenze des
 * Webhostings. Der Zustand muss danach sauber weiterlaufen, ohne doppelte
 * Wirkung.
 */
class WorkerRecoveryTest extends TestCase
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
        ]);
    }

    public function test_abbruch_mitten_im_job_wird_im_naechsten_lauf_fortgesetzt(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $inhalt = SampleFiles::pdf(3);
        $this->ladeDateiHoch($inhalt, 'pdf');

        // Der Cron-Lauf uebernimmt den Job und bricht dann ab.
        $queue = $this->app->make(DatabaseJobQueue::class);
        $job = $queue->claim('abgestuerzter-lauf');

        $this->assertInstanceOf(ProcessingJob::class, $job);
        $this->assertSame(ProcessingJobStatus::GELEAST, $job->getAttribute('status'));

        $job->forceFill(['leased_until' => Carbon::now()->subMinutes(30)])->save();

        // Naechster Cron-Lauf: das abgelaufene Lease wird zurueckgeholt.
        $bericht = $this->verarbeiteQueue();

        $this->assertSame(1, $bericht->reclaimed);

        // Der zurueckgeholte Job ist mit Backoff eingeplant und laeuft im
        // uebernaechsten Lauf.
        Carbon::setTestNow(Carbon::now()->addHour());

        $this->verarbeiteQueue();

        Carbon::setTestNow();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DocumentProcessingStatus::ABGESCHLOSSEN, $dokument->getAttribute('processing_status'));
        $this->assertNotNull($dokument->getAttribute('original_deleted_at'));
    }

    public function test_wiederaufnahme_verbucht_das_volumen_nicht_doppelt(): void
    {
        $inhalt = SampleFiles::pdf(3);
        $this->ladeDateiHoch($inhalt, 'pdf');

        // Erster Lauf: Zusammensetzung und Pruefkette laufen durch.
        $this->verarbeiteQueue();

        $lauf = BillingRun::query()->findOrFail($this->welt()['billingRun']->getKey());
        $ersteVerbuchung = (int) $lauf->getAttribute('uploaded_bytes');

        // Der Zusammensetzungsjob wird kuenstlich erneut eingeplant, so als
        // waere der Lauf zuvor abgebrochen.
        ProcessingJob::query()
            ->where('job_type', 'dokument.zusammensetzen')
            ->update([
                'status' => ProcessingJobStatus::BEREIT->value,
                'attempts' => 0,
                'available_at' => Carbon::now(),
            ]);

        $this->verarbeiteQueue();

        $lauf->refresh();

        $this->assertSame($ersteVerbuchung, (int) $lauf->getAttribute('uploaded_bytes'));
        $this->assertSame(strlen($inhalt), $ersteVerbuchung);
    }

    public function test_erneuter_lauf_erzeugt_keine_zweite_zusammensetzung(): void
    {
        $inhalt = SampleFiles::pdf(2);
        $upload = $this->ladeDateiHoch($inhalt, 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        $this->verarbeiteQueue();

        $dateien = Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix);

        ProcessingJob::query()
            ->where('job_type', 'dokument.zusammensetzen')
            ->update([
                'status' => ProcessingJobStatus::BEREIT->value,
                'attempts' => 0,
                'available_at' => Carbon::now(),
            ]);

        $this->verarbeiteQueue();

        $this->assertSame($dateien, Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
        $this->assertSame(1, Document::query()->count());
    }

    public function test_ein_lauf_ohne_restzeit_stellt_den_job_unveraendert_zurueck(): void
    {
        $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');

        $bericht = $this->verarbeiteQueueMitRestzeit(0.0);

        $this->assertSame(0, $bericht->processed);

        $job = ProcessingJob::query()->firstOrFail();

        $this->assertSame(ProcessingJobStatus::BEREIT, $job->getAttribute('status'));
        $this->assertSame(0, (int) $job->getAttribute('attempts'));
    }

    private function verarbeiteQueueMitRestzeit(float $sekunden): QueueSliceReport
    {
        $queue = $this->app->make(DatabaseJobQueue::class);
        $registry = $this->app->make(DocumentJobRegistry::class)->make();

        return (new QueueSliceRunner($queue, $registry, $this->app))->run('test-lauf', $sekunden, 10);
    }
}
