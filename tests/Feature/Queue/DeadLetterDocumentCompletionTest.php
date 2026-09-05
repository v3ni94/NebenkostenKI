<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Enums\DeletionStatus;
use App\Enums\DocumentProcessingStatus;
use App\Enums\ProcessingJobStatus;
use App\Jobs\DocumentJobType;
use App\Jobs\DocumentPipeline;
use App\Models\ProcessingJob;
use App\Services\Queue\DatabaseJobQueue;
use App\Services\Queue\JobContext;
use App\Services\Queue\JobHandlerRegistry;
use App\Services\Queue\ProcessingJobHandler;
use App\Services\Queue\QueueSliceRunner;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Ai\Concerns\BuildsAiIntegration;
use Tests\TestCase;

/**
 * Ein Teiljob, der endgueltig in den Dead-Letter-Status geht, darf das
 * Dokument nicht in KLASSIFIZIERUNG oder EXTRAKTION stehen lassen. Nach
 * Abschnitt 6.3 Schritt 16 wird das Dokument gekennzeichnet und die
 * Quelldatei sofort geloescht, egal ob der Uebergang durch eine unerwartete
 * Ausnahme im letzten Versuch oder durch ein abgelaufenes Lease erfolgt.
 */
class DeadLetterDocumentCompletionTest extends TestCase
{
    use BuildsAiIntegration, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiIntegration();
    }

    public function test_abgelaufenes_lease_nach_erschoepften_versuchen_schliesst_das_dokument_ab(): void
    {
        [$document, $upload, $prefix] = $this->dokumentMitQuelldatei();

        $queue = $this->app->make(DatabaseJobQueue::class);
        $this->app->make(DocumentPipeline::class)->queueExtraction($document);

        $job = $queue->claim('abgestuerzter-lauf');
        $this->assertInstanceOf(ProcessingJob::class, $job);

        // Der Worker ist im letzten Versuch mitten im Job abgebrochen.
        $job->forceFill([
            'attempts' => $job->getAttribute('max_attempts'),
            'leased_until' => Carbon::now()->subMinutes(10),
        ])->save();

        $this->assertSame(1, $queue->reclaimExpiredLeases());

        $job->refresh();
        $document->refresh();
        $upload->refresh();

        $this->assertSame(ProcessingJobStatus::DEAD_LETTER, $job->getAttribute('status'));
        $this->assertSame(DocumentProcessingStatus::FEHLGESCHLAGEN, $document->getAttribute('processing_status'));
        $this->assertSame(UploadErrorCode::UNERWARTETER_FEHLER->value, $document->getAttribute('failure_code'));
        $this->assertSame(DeletionStatus::ERFOLGREICH, $document->getAttribute('deletion_status'));
        $this->assertTrue($upload->getAttribute('is_tombstone'));
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
    }

    public function test_unerwartete_ausnahme_im_letzten_versuch_schliesst_das_dokument_ab(): void
    {
        [$document, $upload, $prefix] = $this->dokumentMitQuelldatei();

        $queue = $this->app->make(DatabaseJobQueue::class);
        $job = $this->app->make(DocumentPipeline::class)->queueExtraction($document);

        // Zwei Versuche sind bereits verbraucht, der naechste ist der letzte.
        $job->forceFill(['attempts' => (int) $job->getAttribute('max_attempts') - 1])->save();

        $registry = new JobHandlerRegistry([
            DocumentJobType::EXTRAHIEREN->value => AbstuerzenderDokumentHandler::class,
        ]);

        $report = (new QueueSliceRunner($queue, $registry, $this->app))->run('lauf-a', 30.0, 5);

        $this->assertSame(1, $report->deadLettered);

        $document->refresh();
        $upload->refresh();

        $this->assertSame(DocumentProcessingStatus::FEHLGESCHLAGEN, $document->getAttribute('processing_status'));
        $this->assertSame(UploadErrorCode::UNERWARTETER_FEHLER->value, $document->getAttribute('failure_code'));
        $this->assertStringNotContainsString('Grundsteuerbescheid', (string) $document->getAttribute('failure_message'));
        $this->assertSame(DeletionStatus::ERFOLGREICH, $document->getAttribute('deletion_status'));
        $this->assertTrue($upload->getAttribute('is_tombstone'));
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
    }

    public function test_unerwartete_ausnahme_vor_dem_letzten_versuch_laesst_die_quelldatei_fuer_die_wiederholung(): void
    {
        [$document, , $prefix] = $this->dokumentMitQuelldatei();

        $queue = $this->app->make(DatabaseJobQueue::class);
        $this->app->make(DocumentPipeline::class)->queueExtraction($document);

        $registry = new JobHandlerRegistry([
            DocumentJobType::EXTRAHIEREN->value => AbstuerzenderDokumentHandler::class,
        ]);

        $report = (new QueueSliceRunner($queue, $registry, $this->app))->run('lauf-a', 30.0, 5);

        $this->assertSame(1, $report->failed);
        $this->assertSame(0, $report->deadLettered);

        $document->refresh();

        $this->assertSame(DocumentProcessingStatus::EXTRAKTION, $document->getAttribute('processing_status'));
        $this->assertNotSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
    }
}

/**
 * Testhandler, der mit einer inhaltsreichen Ausnahme abstuerzt.
 */
final class AbstuerzenderDokumentHandler implements ProcessingJobHandler
{
    public function handle(ProcessingJob $job, JobContext $context): void
    {
        throw new RuntimeException('Grundsteuerbescheid Zeile 3: 1.234,56 EUR');
    }
}
