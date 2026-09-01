<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ProcessingJobStatus;
use App\Models\ProcessingJob;

/**
 * Verarbeitung, Retry und Dead Letter (Masterprompt 20).
 */
final class VerarbeitungTest extends AdminTestCase
{
    public function test_die_seite_zeigt_fehlgeschlagene_und_endgueltig_fehlgeschlagene_jobs(): void
    {
        ProcessingJob::factory()->create([
            'status' => ProcessingJobStatus::FEHLGESCHLAGEN,
            'attempts' => 1,
            'error_code' => 'EXTRACTION_TIMEOUT',
        ]);

        ProcessingJob::factory()->deadLetter()->create([
            'job_type' => 'dokument.klassifizieren',
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/verarbeitung');

        $antwort->assertOk();
        $antwort->assertSee('Fehlgeschlagene Teiljobs');
        $antwort->assertSee('Dead Letter');
        $antwort->assertSee('EXTRACTION_TIMEOUT');
        $antwort->assertSee('dokument.klassifizieren');
    }

    public function test_ein_fehlgeschlagener_job_kann_erneut_eingestellt_werden(): void
    {
        /** @var ProcessingJob $job */
        $job = ProcessingJob::factory()->create([
            'status' => ProcessingJobStatus::FEHLGESCHLAGEN,
            'attempts' => 2,
            'error_code' => 'EXTRACTION_TIMEOUT',
            'last_error' => 'Die Auswertung hat zu lange gedauert.',
            'finished_at' => now(),
        ]);

        $antwort = $this->actingAs($this->interneKennung())
            ->post('/admin/verarbeitung/jobs/'.$job->getKey().'/wiederholen');

        $antwort->assertRedirect('/admin/verarbeitung');

        /** @var ProcessingJob $frisch */
        $frisch = ProcessingJob::query()->findOrFail($job->getKey());

        self::assertSame(ProcessingJobStatus::BEREIT, $frisch->getAttribute('status'));
        self::assertSame(0, (int) $frisch->getAttribute('attempts'));
        self::assertNull($frisch->getAttribute('error_code'));
        self::assertNull($frisch->getAttribute('last_error'));
        self::assertNull($frisch->getAttribute('finished_at'));
    }

    public function test_ein_dead_letter_job_kann_erneut_eingestellt_werden_und_wird_protokolliert(): void
    {
        /** @var ProcessingJob $job */
        $job = ProcessingJob::factory()->deadLetter()->create();

        $this->actingAs($this->interneKennung())
            ->post('/admin/verarbeitung/jobs/'.$job->getKey().'/wiederholen')
            ->assertRedirect('/admin/verarbeitung');

        self::assertSame(
            ProcessingJobStatus::BEREIT,
            ProcessingJob::query()->findOrFail($job->getKey())->getAttribute('status'),
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.job.retried',
            'subject_id' => $job->getKey(),
        ]);
    }

    public function test_ein_erfolgreicher_job_wird_nicht_erneut_eingestellt(): void
    {
        /** @var ProcessingJob $job */
        $job = ProcessingJob::factory()->create([
            'status' => ProcessingJobStatus::ERFOLGREICH,
            'attempts' => 1,
        ]);

        $this->actingAs($this->interneKennung())
            ->post('/admin/verarbeitung/jobs/'.$job->getKey().'/wiederholen')
            ->assertRedirect('/admin/verarbeitung');

        self::assertSame(
            ProcessingJobStatus::ERFOLGREICH,
            ProcessingJob::query()->findOrFail($job->getKey())->getAttribute('status'),
        );
    }

    public function test_die_seite_zeigt_keine_nutzlast(): void
    {
        ProcessingJob::factory()->deadLetter()->create([
            'payload' => ['dokument_id' => 'geheime-testreferenz-0001'],
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/verarbeitung');

        $antwort->assertOk();
        $antwort->assertDontSee('geheime-testreferenz-0001');
    }
}
