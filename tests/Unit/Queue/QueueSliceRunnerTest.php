<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use App\Enums\ProcessingJobStatus;
use App\Models\ProcessingJob;
use App\Services\Queue\BackoffStrategy;
use App\Services\Queue\DatabaseJobQueue;
use App\Services\Queue\JobContext;
use App\Services\Queue\JobFailedException;
use App\Services\Queue\JobHandlerRegistry;
use App\Services\Queue\ProcessingJobHandler;
use App\Services\Queue\QueueSliceRunner;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Prueft den begrenzten Queue-Lauf.
 *
 * Auf IONOS Profil A ruft ein Cronjob kurze Laeufe auf. Der Lauf muss deshalb
 * die Restzeit einhalten, abgelaufene Leases zurueckholen und darf niemals
 * eine unbekannte Ausnahmemeldung in die Datenbank schreiben (Abschnitt 19).
 */
class QueueSliceRunnerTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseJobQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new DatabaseJobQueue(new BackoffStrategy(30, 2, 3600), leaseSeconds: 300);

        ErfolgreicherTesthandler::$aufrufe = 0;
    }

    public function test_verarbeitet_faellige_jobs_und_meldet_erfolg(): void
    {
        $this->queue->push('test.erfolg');
        $this->queue->push('test.erfolg');

        $report = $this->runner(['test.erfolg' => ErfolgreicherTesthandler::class])->run('lauf-a');

        $this->assertSame(2, $report->processed);
        $this->assertSame(2, $report->succeeded);
        $this->assertSame(0, $report->failed);
        $this->assertSame(2, ErfolgreicherTesthandler::$aufrufe);
        $this->assertSame(
            2,
            ProcessingJob::query()->where('status', ProcessingJobStatus::ERFOLGREICH->value)->count()
        );
    }

    public function test_endgueltiger_fehler_landet_im_dead_letter(): void
    {
        $this->queue->push('test.endgueltig', [], null, null, null, 100, 5);

        $report = $this->runner(['test.endgueltig' => EndgueltigScheiternderTesthandler::class])->run('lauf-a');

        $this->assertSame(1, $report->deadLettered);

        $job = ProcessingJob::query()->firstOrFail();

        $this->assertSame(ProcessingJobStatus::DEAD_LETTER, $job->getAttribute('status'));
        $this->assertSame(UploadErrorCode::MIME_TAEUSCHUNG->value, $job->getAttribute('error_code'));
    }

    public function test_unerwartete_ausnahme_wird_ohne_meldung_protokolliert(): void
    {
        $this->queue->push('test.absturz', [], null, null, null, 100, 3);

        $this->runner(['test.absturz' => AbstuerzenderTesthandler::class])->run('lauf-a');

        $job = ProcessingJob::query()->firstOrFail();

        $this->assertSame(UploadErrorCode::UNERWARTETER_FEHLER->value, $job->getAttribute('error_code'));
        $this->assertStringNotContainsString(
            'Grundsteuerbescheid',
            (string) $job->getAttribute('last_error'),
            'Eine unbekannte Ausnahmemeldung darf niemals gespeichert werden.'
        );
        $this->assertSame(
            UploadErrorCode::UNERWARTETER_FEHLER->message(),
            $job->getAttribute('last_error')
        );
    }

    public function test_unbekannter_jobtyp_wird_endgueltig_abgelehnt(): void
    {
        $this->queue->push('test.unbekannt');

        $report = $this->runner([])->run('lauf-a');

        $this->assertSame(1, $report->deadLettered);
    }

    public function test_holt_abgelaufene_leases_zu_beginn_zurueck(): void
    {
        $this->queue->push('test.erfolg', [], null, null, null, 100, 3);

        $job = $this->queue->claim('abgestuerzter-lauf');
        $this->assertInstanceOf(ProcessingJob::class, $job);

        $job->forceFill(['leased_until' => Carbon::now()->subMinutes(30)])->save();

        $report = $this->runner(['test.erfolg' => ErfolgreicherTesthandler::class])->run('lauf-neu');

        $this->assertSame(1, $report->reclaimed);
        $this->assertSame(ProcessingJobStatus::BEREIT, ProcessingJob::query()->firstOrFail()->getAttribute('status'));
    }

    public function test_haelt_die_restlaufzeit_ein(): void
    {
        $this->queue->push('test.erfolg');
        $this->queue->push('test.erfolg');

        $report = $this->runner(['test.erfolg' => ErfolgreicherTesthandler::class])->run('lauf-a', 0.0);

        $this->assertSame(0, $report->processed);
        $this->assertSame(0, ErfolgreicherTesthandler::$aufrufe);
    }

    public function test_beachtet_die_hoechstzahl_der_teiljobs(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->queue->push('test.erfolg');
        }

        $report = $this->runner(['test.erfolg' => ErfolgreicherTesthandler::class])->run('lauf-a', 30.0, 2);

        $this->assertSame(2, $report->processed);
        $this->assertSame(
            3,
            ProcessingJob::query()->where('status', ProcessingJobStatus::BEREIT->value)->count()
        );
    }

    /**
     * @param  array<string, class-string<ProcessingJobHandler>>  $handlers
     */
    private function runner(array $handlers): QueueSliceRunner
    {
        return new QueueSliceRunner($this->queue, new JobHandlerRegistry($handlers), $this->app);
    }
}

/**
 * Testhandler, der immer erfolgreich ist.
 */
final class ErfolgreicherTesthandler implements ProcessingJobHandler
{
    public static int $aufrufe = 0;

    public function handle(ProcessingJob $job, JobContext $context): void
    {
        self::$aufrufe++;

        $context->heartbeat();
    }
}

/**
 * Testhandler mit endgueltigem Fehler.
 */
final class EndgueltigScheiternderTesthandler implements ProcessingJobHandler
{
    public function handle(ProcessingJob $job, JobContext $context): void
    {
        throw JobFailedException::permanent(UploadErrorCode::MIME_TAEUSCHUNG);
    }
}

/**
 * Testhandler, der mit einer inhaltsreichen Ausnahme abstuerzt. Der Inhalt darf
 * niemals in der Datenbank landen.
 */
final class AbstuerzenderTesthandler implements ProcessingJobHandler
{
    public function handle(ProcessingJob $job, JobContext $context): void
    {
        throw new RuntimeException('Grundsteuerbescheid Zeile 3: 1.234,56 EUR');
    }
}
