<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Enums\ProcessingJobStatus;
use App\Models\ProcessingJob;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Stellt einen fehlgeschlagenen oder endgueltig fehlgeschlagenen Teiljob
 * erneut in die Warteschlange (Masterprompt 20).
 *
 * REGELN
 *
 *  1. Erneut angestossen werden ausschliesslich Jobs im Status
 *     FEHLGESCHLAGEN oder DEAD_LETTER. Ein laufender oder erfolgreicher Job
 *     wird nicht angetastet.
 *  2. Der Versuchszaehler wird auf 0 zurueckgesetzt, damit der Job die
 *     regulaeren max_attempts erneut zur Verfuegung hat. Das ist eine
 *     ausdrueckliche Handlung eines internen Nutzers und wird protokolliert.
 *  3. Lease und Fehlerangaben werden geleert, die Nutzlast bleibt unveraendert.
 *     Es wird keine Nutzlast im Adminbereich erzeugt oder ergaenzt.
 *  4. Jede Wiederholung erzeugt einen Audit-Eintrag mit Akteur, Jobart und
 *     vorherigem Status.
 */
final class RetryProcessingJob
{
    public function __construct(private readonly AdminAuditRecorder $audit) {}

    public function isRetryable(ProcessingJob $job): bool
    {
        $status = $job->getAttribute('status');

        return $status === ProcessingJobStatus::FEHLGESCHLAGEN
            || $status === ProcessingJobStatus::DEAD_LETTER;
    }

    public function __invoke(ProcessingJob $job, User $actor): bool
    {
        if (! $this->isRetryable($job)) {
            return false;
        }

        $previous = $job->getAttribute('status');

        $job->forceFill([
            'status' => ProcessingJobStatus::BEREIT,
            'attempts' => 0,
            'lease_owner' => null,
            'leased_until' => null,
            'heartbeat_at' => null,
            'available_at' => Carbon::now(),
            'started_at' => null,
            'finished_at' => null,
            'error_code' => null,
            'last_error' => null,
        ])->save();

        $this->audit->record(
            action: 'admin.job.retried',
            actor: $actor,
            subject: $job,
            metadata: [
                'jobart' => (string) $job->getAttribute('job_type'),
                'vorheriger_status' => $previous instanceof ProcessingJobStatus
                    ? $previous->value
                    : (string) $previous,
            ],
        );

        return true;
    }
}
