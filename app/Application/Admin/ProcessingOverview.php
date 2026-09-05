<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Enums\DocumentProcessingStatus;
use App\Enums\ProcessingJobStatus;
use App\Models\Document;
use App\Models\ProcessingJob;
use Illuminate\Support\Carbon;

/**
 * Status der Dokumentverarbeitung und der Teiljobs (Masterprompt 20).
 *
 * DATENSPARSAMKEIT: Es werden keine Rohdaten, keine Prompts, keine
 * Nutzlasten und keine Fundstellen angezeigt. Sichtbar sind ausschliesslich
 * Jobart, Status, Versuchszaehler, Fehlercode und Zeitpunkte.
 */
final class ProcessingOverview
{
    /**
     * Anzahl der Dokumente je Verarbeitungsstatus.
     *
     * @return array<string, int>
     */
    public function documentStatusCounts(): array
    {
        $counts = [];

        foreach (DocumentProcessingStatus::cases() as $status) {
            $counts[$status->value] = Document::query()
                ->where('processing_status', $status->value)
                ->count();
        }

        return $counts;
    }

    /**
     * Anzahl der Teiljobs je Status.
     *
     * @return array<string, int>
     */
    public function jobStatusCounts(): array
    {
        $counts = [];

        foreach (ProcessingJobStatus::cases() as $status) {
            $counts[$status->value] = ProcessingJob::query()
                ->where('status', $status->value)
                ->count();
        }

        return $counts;
    }

    /**
     * Fehlgeschlagene Teiljobs, die erneut angestossen werden koennen.
     *
     * @return list<array{
     *     id: string,
     *     jobart: string,
     *     status: string,
     *     versuche: int,
     *     max_versuche: int,
     *     fehlercode: string|null,
     *     alter_minuten: int|null
     * }>
     */
    public function failedJobs(int $limit = 50): array
    {
        return $this->rows(
            ProcessingJob::query()
                ->where('status', ProcessingJobStatus::FEHLGESCHLAGEN->value)
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get()
                ->all(),
        );
    }

    /**
     * Endgueltig fehlgeschlagene Teiljobs.
     *
     * @return list<array{
     *     id: string,
     *     jobart: string,
     *     status: string,
     *     versuche: int,
     *     max_versuche: int,
     *     fehlercode: string|null,
     *     alter_minuten: int|null
     * }>
     */
    public function deadLetterJobs(int $limit = 50): array
    {
        return $this->rows(
            ProcessingJob::query()
                ->where('status', ProcessingJobStatus::DEAD_LETTER->value)
                ->orderByDesc('finished_at')
                ->limit($limit)
                ->get()
                ->all(),
        );
    }

    public function retryableCount(): int
    {
        return ProcessingJob::query()
            ->whereIn('status', [
                ProcessingJobStatus::FEHLGESCHLAGEN->value,
                ProcessingJobStatus::DEAD_LETTER->value,
            ])
            ->count();
    }

    /**
     * @param  list<ProcessingJob>  $jobs
     * @return list<array{
     *     id: string,
     *     jobart: string,
     *     status: string,
     *     versuche: int,
     *     max_versuche: int,
     *     fehlercode: string|null,
     *     alter_minuten: int|null
     * }>
     */
    private function rows(array $jobs): array
    {
        $rows = [];

        foreach ($jobs as $job) {
            $status = $job->getAttribute('status');
            $code = $job->getAttribute('error_code');
            $updated = $job->getAttribute('updated_at');

            $rows[] = [
                'id' => (string) $job->getKey(),
                'jobart' => (string) $job->getAttribute('job_type'),
                'status' => $status instanceof ProcessingJobStatus ? $status->label() : (string) $status,
                'versuche' => (int) $job->getAttribute('attempts'),
                'max_versuche' => (int) $job->getAttribute('max_attempts'),
                'fehlercode' => is_string($code) && $code !== '' ? $code : null,
                'alter_minuten' => $updated === null
                    ? null
                    : (int) Carbon::parse((string) $updated)->diffInMinutes(Carbon::now(), true),
            ];
        }

        return $rows;
    }
}
