<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Enums\ProcessingJobStatus;
use App\Models\ProcessingJob;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Begrenzter Queue-Lauf fuer IONOS Profil A (ADR-006).
 *
 * Ein Cronjob ruft schedule:run auf, der Scheduler startet diesen Lauf mit
 * einer harten Restzeit. Der Lauf beendet sich selbst, bevor die Zeit
 * ueberschritten wird, und stellt einen begonnenen Job unveraendert zurueck.
 * Ein dauerhafter Worker wird nicht vorausgesetzt.
 *
 * DATENSCHUTZ: Eine unerwartete Ausnahme wird NICHT mit ihrer Meldung
 * gespeichert. Ausnahmemeldungen koennen Pfade, Dateinamen oder Ausschnitte
 * eines Dokuments enthalten. Persistiert wird ausschliesslich der zentrale
 * Fehlercode und der dazugehoerige allgemeine deutsche Hinweistext.
 */
final class QueueSliceRunner
{
    public function __construct(
        private readonly DatabaseJobQueue $queue,
        private readonly JobHandlerRegistry $registry,
        private readonly Container $container,
    ) {}

    /**
     * @param  float  $maxSeconds  Restlaufzeit des Cron-Laufs
     * @param  int  $maxJobs  Sicherheitsgrenze gegen Endlosschleifen
     */
    public function run(string $owner, float $maxSeconds = 45.0, int $maxJobs = 100): QueueSliceReport
    {
        $deadline = microtime(true) + $maxSeconds;

        $reclaimed = $this->queue->reclaimExpiredLeases();

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $deadLettered = 0;
        $released = 0;
        $errorCodes = [];

        while ($processed < $maxJobs && microtime(true) < $deadline) {
            $job = $this->queue->claim($owner);

            if (! $job instanceof ProcessingJob) {
                break;
            }

            $processed++;

            $context = new JobContext($this->queue, $job, $owner, $deadline);

            if (! $context->hasTimeLeft(2.0)) {
                $this->queue->release($job);
                $released++;

                break;
            }

            $outcome = $this->execute($job, $context);

            if ($outcome === null) {
                $succeeded++;

                continue;
            }

            $status = $this->queue->fail($job, $outcome['code'], $outcome['permanent']);

            $errorCodes[$outcome['code']->value] = ($errorCodes[$outcome['code']->value] ?? 0) + 1;

            if ($status === ProcessingJobStatus::DEAD_LETTER) {
                $deadLettered++;
            } else {
                $failed++;
            }
        }

        return new QueueSliceReport(
            $processed,
            $succeeded,
            $failed,
            $deadLettered,
            $reclaimed,
            $released,
            $errorCodes,
        );
    }

    /**
     * @return array{code: UploadErrorCode, permanent: bool}|null null bei Erfolg
     */
    private function execute(ProcessingJob $job, JobContext $context): ?array
    {
        $jobType = (string) $job->getAttribute('job_type');

        if (! $this->registry->has($jobType)) {
            return ['code' => UploadErrorCode::UNERWARTETER_FEHLER, 'permanent' => true];
        }

        try {
            $handler = $this->registry->resolve($this->container, $jobType);
            $handler->handle($job, $context);

            $this->queue->succeed($job);

            return null;
        } catch (JobFailedException $exception) {
            return ['code' => $exception->errorCode, 'permanent' => $exception->permanent];
        } catch (UploadRejectedException $exception) {
            return ['code' => $exception->errorCode, 'permanent' => $exception->isPermanent()];
        } catch (Throwable) {
            // Bewusst ohne Meldung und ohne Stacktrace. Der Inhalt einer
            // unbekannten Ausnahme darf nicht in die Datenbank gelangen.
            return ['code' => UploadErrorCode::UNERWARTETER_FEHLER, 'permanent' => false];
        }
    }
}
