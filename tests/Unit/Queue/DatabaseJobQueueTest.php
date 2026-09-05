<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use App\Enums\ProcessingJobStatus;
use App\Models\Document;
use App\Models\ProcessingJob;
use App\Services\Queue\BackoffStrategy;
use App\Services\Queue\DatabaseJobQueue;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Prueft Lease, Heartbeat, Retry, exponentiellen Backoff und Dead Letter der
 * datenbankgestuetzten Queue (ADR-006, Abschnitt 3.5).
 *
 * Das Betriebsmodell setzt weder Redis noch einen dauerhaften Worker voraus.
 */
class DatabaseJobQueueTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseJobQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new DatabaseJobQueue(new BackoffStrategy(30, 2, 3600), leaseSeconds: 300);
    }

    public function test_lease_verhindert_doppelverarbeitung(): void
    {
        $this->queue->push('test.job', ['dokument_id' => 'D1']);

        $erster = $this->queue->claim('lauf-a');
        $zweiter = $this->queue->claim('lauf-b');

        $this->assertInstanceOf(ProcessingJob::class, $erster);
        $this->assertNull($zweiter, 'Ein geleaster Job darf von einem zweiten Lauf nicht uebernommen werden.');
        $this->assertSame(ProcessingJobStatus::GELEAST, $erster->getAttribute('status'));
        $this->assertSame('lauf-a', $erster->getAttribute('lease_owner'));
        $this->assertSame(1, $erster->getAttribute('attempts'));
    }

    public function test_versuchszaehler_steigt_bereits_beim_uebernehmen(): void
    {
        $this->queue->push('test.job');

        $job = $this->queue->claim('lauf-a');
        $this->assertInstanceOf(ProcessingJob::class, $job);
        $this->assertSame(1, $job->getAttribute('attempts'));

        // Abbruch mitten im Job: das Lease laeuft ab, der Versuch ist gezaehlt.
        $job->forceFill(['leased_until' => Carbon::now()->subMinute()])->save();
        $this->queue->reclaimExpiredLeases();

        Carbon::setTestNow(Carbon::now()->addHour());

        $wieder = $this->queue->claim('lauf-b');
        $this->assertInstanceOf(ProcessingJob::class, $wieder);
        $this->assertSame(2, $wieder->getAttribute('attempts'));

        Carbon::setTestNow();
    }

    public function test_heartbeat_verlaengert_das_lease(): void
    {
        $this->queue->push('test.job');
        $job = $this->queue->claim('lauf-a');
        $this->assertInstanceOf(ProcessingJob::class, $job);

        $vorher = $job->getAttribute('leased_until');

        Carbon::setTestNow(Carbon::now()->addMinutes(2));

        $this->assertTrue($this->queue->heartbeat($job, 'lauf-a'));

        $job->refresh();

        $this->assertTrue($job->getAttribute('leased_until')->greaterThan($vorher));

        Carbon::setTestNow();
    }

    public function test_heartbeat_eines_fremden_laufs_schlaegt_fehl(): void
    {
        $this->queue->push('test.job');
        $job = $this->queue->claim('lauf-a');
        $this->assertInstanceOf(ProcessingJob::class, $job);

        $this->assertFalse($this->queue->heartbeat($job, 'lauf-fremd'));
    }

    public function test_backoff_waechst_mit_jedem_fehlversuch(): void
    {
        Carbon::setTestNow(Carbon::create(2027, 3, 1, 12, 0, 0));

        $this->queue->push('test.job', [], null, null, null, 100, 5);

        $verzoegerungen = [];

        for ($versuch = 1; $versuch <= 3; $versuch++) {
            $job = $this->queue->claim('lauf-a');
            $this->assertInstanceOf(ProcessingJob::class, $job);

            $this->queue->fail($job, UploadErrorCode::UNERWARTETER_FEHLER);

            $job->refresh();

            $verzoegerungen[] = Carbon::now()->diffInSeconds($job->getAttribute('available_at'), false);

            // Naechster Cron-Lauf, deutlich nach der geplanten Verzoegerung.
            Carbon::setTestNow(Carbon::now()->addHour());
        }

        Carbon::setTestNow();

        $this->assertSame([30, 60, 120], array_map('intval', $verzoegerungen));
    }

    public function test_dead_letter_nach_maximalen_versuchen(): void
    {
        $this->queue->push('test.job', [], null, null, null, 100, 2);

        for ($versuch = 1; $versuch <= 2; $versuch++) {
            Carbon::setTestNow(Carbon::now()->addDay());

            $job = $this->queue->claim('lauf-a');
            $this->assertInstanceOf(ProcessingJob::class, $job);

            $status = $this->queue->fail($job, UploadErrorCode::EXTRAKTION_FEHLGESCHLAGEN);

            $erwartet = $versuch === 2 ? ProcessingJobStatus::DEAD_LETTER : ProcessingJobStatus::BEREIT;
            $this->assertSame($erwartet, $status);
        }

        Carbon::setTestNow();

        $this->assertSame(1, ProcessingJob::query()->deadLetter()->count());
    }

    public function test_endgueltiger_fehler_fuehrt_sofort_in_den_dead_letter_status(): void
    {
        $this->queue->push('test.job', [], null, null, null, 100, 5);

        $job = $this->queue->claim('lauf-a');
        $this->assertInstanceOf(ProcessingJob::class, $job);

        $status = $this->queue->fail($job, UploadErrorCode::MIME_TAEUSCHUNG, permanent: true);

        $this->assertSame(ProcessingJobStatus::DEAD_LETTER, $status);
        $this->assertSame(UploadErrorCode::MIME_TAEUSCHUNG->value, $job->getAttribute('error_code'));
        $this->assertSame(UploadErrorCode::MIME_TAEUSCHUNG->message(), $job->getAttribute('last_error'));
    }

    public function test_abgelaufenes_lease_wird_zurueckgeholt(): void
    {
        $this->queue->push('test.job', [], null, null, null, 100, 3);

        $job = $this->queue->claim('lauf-a');
        $this->assertInstanceOf(ProcessingJob::class, $job);

        // Der Worker ist mitten im Job abgebrochen.
        $job->forceFill(['leased_until' => Carbon::now()->subMinutes(10)])->save();

        $this->assertSame(1, $this->queue->reclaimExpiredLeases());

        $job->refresh();

        $this->assertSame(ProcessingJobStatus::BEREIT, $job->getAttribute('status'));
        $this->assertNull($job->getAttribute('lease_owner'));
        $this->assertSame(UploadErrorCode::LEASE_ABGELAUFEN->value, $job->getAttribute('error_code'));
    }

    public function test_abgelaufenes_lease_nach_erschoepften_versuchen_wird_dead_letter(): void
    {
        $this->queue->push('test.job', [], null, null, null, 100, 1);

        $job = $this->queue->claim('lauf-a');
        $this->assertInstanceOf(ProcessingJob::class, $job);

        $job->forceFill(['leased_until' => Carbon::now()->subMinutes(10)])->save();

        $this->queue->reclaimExpiredLeases();

        $job->refresh();

        $this->assertSame(ProcessingJobStatus::DEAD_LETTER, $job->getAttribute('status'));
    }

    public function test_zurueckstellen_nimmt_den_versuch_zurueck(): void
    {
        $this->queue->push('test.job');

        $job = $this->queue->claim('lauf-a');
        $this->assertInstanceOf(ProcessingJob::class, $job);
        $this->assertSame(1, $job->getAttribute('attempts'));

        $this->queue->release($job);
        $job->refresh();

        $this->assertSame(ProcessingJobStatus::BEREIT, $job->getAttribute('status'));
        $this->assertSame(0, $job->getAttribute('attempts'));
    }

    public function test_erfolg_beendet_den_job_ohne_fehlercode(): void
    {
        $this->queue->push('test.job');

        $job = $this->queue->claim('lauf-a');
        $this->assertInstanceOf(ProcessingJob::class, $job);

        $this->queue->succeed($job);

        $this->assertSame(ProcessingJobStatus::ERFOLGREICH, $job->getAttribute('status'));
        $this->assertNull($job->getAttribute('error_code'));
        $this->assertNotNull($job->getAttribute('finished_at'));
    }

    public function test_hoehere_prioritaet_wird_zuerst_uebernommen(): void
    {
        $this->queue->push('test.spaet', [], null, null, null, 200);
        $this->queue->push('test.frueh', [], null, null, null, 10);

        $job = $this->queue->claim('lauf-a');

        $this->assertInstanceOf(ProcessingJob::class, $job);
        $this->assertSame('test.frueh', $job->getAttribute('job_type'));
    }

    public function test_push_once_ist_idempotent(): void
    {
        $dokument = Document::factory()->create();

        $erster = $this->queue->pushOnce('test.job', (string) $dokument->getKey());
        $zweiter = $this->queue->pushOnce('test.job', (string) $dokument->getKey());

        $this->assertSame($erster->getKey(), $zweiter->getKey());
        $this->assertSame(1, ProcessingJob::query()->count());
    }

    public function test_payload_sperre_greift_beim_einstellen(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->queue->push('test.job', ['ocr_text' => 'Grundsteuerbescheid Zeile 1']);
    }

    public function test_offene_jobs_eines_dokuments_werden_abgebrochen(): void
    {
        $eines = Document::factory()->create();
        $anderes = Document::factory()->create();

        $this->queue->pushOnce('test.a', (string) $eines->getKey());
        $this->queue->pushOnce('test.b', (string) $eines->getKey());
        $this->queue->pushOnce('test.c', (string) $anderes->getKey());

        $this->assertSame(2, $this->queue->cancelForDocument((string) $eines->getKey()));

        $this->assertSame(
            2,
            ProcessingJob::query()->where('status', ProcessingJobStatus::ABGEBROCHEN->value)->count()
        );
    }
}
