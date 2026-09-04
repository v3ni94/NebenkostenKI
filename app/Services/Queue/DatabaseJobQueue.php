<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Enums\ProcessingJobStatus;
use App\Models\ProcessingJob;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datenbankgestuetzte Queue mit Lease, Heartbeat, Retry, exponentiellem
 * Backoff und Dead-Letter-Status (ADR-006, Abschnitt 3.5).
 *
 * WARUM SO
 * --------
 * IONOS Profil A garantiert weder Redis noch einen dauerhaften Worker. Ein
 * Cronjob ruft in kurzen Abstaenden einen begrenzten Lauf auf. Deshalb gilt:
 *
 * - Ein Job wird per Lease exklusiv uebernommen. Ein zweiter Lauf sieht den
 *   Job nicht, solange das Lease gilt. Das verhindert Doppelverarbeitung, auch
 *   wenn zwei Cron-Laeufe ueberlappen.
 * - Der Versuchszaehler wird beim Uebernehmen erhoeht, nicht erst beim
 *   Scheitern. Bricht ein Worker mitten im Job ab, ist der Versuch trotzdem
 *   gezaehlt und der Job laeuft nach Ablauf des Lease erneut an, ohne endlos
 *   zu kreisen.
 * - Der Heartbeat verlaengert das Lease waehrend eines laengeren Teilschritts.
 * - Nach max_attempts geht der Job in DEAD_LETTER und wird nicht mehr
 *   uebernommen. Der Aufrufer loescht dann die Quelldaten sofort.
 *
 * DATENSCHUTZ: In payload gehoeren ausschliesslich Referenz-IDs und technische
 * Parameter. Das erzwingt JobPayloadGuard bei jedem Einstellen.
 */
final class DatabaseJobQueue
{
    /**
     * Laufzeit eines Lease. Ein Cron-Lauf ist auf 50 Sekunden begrenzt, das
     * Lease deckt mit Reserve auch einen langsamen Providerabruf ab.
     */
    public const DEFAULT_LEASE_SECONDS = 300;

    /**
     * @param  DeadLetterListener|null  $deadLetterListener  schliesst das zugehoerige Dokument ab,
     *                                                       sobald ein Job endgueltig in DEAD_LETTER geht
     */
    public function __construct(
        private readonly BackoffStrategy $backoff = new BackoffStrategy,
        private readonly JobPayloadGuard $payloadGuard = new JobPayloadGuard,
        private readonly int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
        private readonly ?DeadLetterListener $deadLetterListener = null,
    ) {}

    /**
     * Stellt einen Teiljob ein.
     *
     * @param  array<string, mixed>  $payload  nur Referenz-IDs und technische Parameter
     */
    public function push(
        string $jobType,
        array $payload = [],
        ?string $organizationId = null,
        ?string $billingRunId = null,
        ?string $documentId = null,
        int $priority = 100,
        int $maxAttempts = 3,
        ?Carbon $availableAt = null,
    ): ProcessingJob {
        $model = new ProcessingJob;

        $model->fill([
            'organization_id' => $organizationId,
            'billing_run_id' => $billingRunId,
            'document_id' => $documentId,
            'job_type' => $jobType,
            'status' => ProcessingJobStatus::BEREIT,
            'priority' => $priority,
            'attempts' => 0,
            'max_attempts' => max(1, $maxAttempts),
            'available_at' => $availableAt ?? Carbon::now(),
            'payload' => $this->payloadGuard->sanitize($payload),
        ]);

        $model->save();

        return $model;
    }

    /**
     * Stellt einen Teiljob nur ein, wenn er noch nicht offen vorliegt.
     * Dadurch bleibt ein erneuter Aufruf desselben Schritts idempotent.
     *
     * @param  array<string, mixed>  $payload
     */
    public function pushOnce(
        string $jobType,
        ?string $documentId,
        array $payload = [],
        ?string $organizationId = null,
        ?string $billingRunId = null,
        int $priority = 100,
        int $maxAttempts = 3,
    ): ProcessingJob {
        $existing = ProcessingJob::query()
            ->where('job_type', $jobType)
            ->where('document_id', $documentId)
            ->whereIn('status', [ProcessingJobStatus::BEREIT->value, ProcessingJobStatus::GELEAST->value])
            ->first();

        if ($existing instanceof ProcessingJob) {
            return $existing;
        }

        return $this->push(
            $jobType,
            $payload,
            $organizationId,
            $billingRunId,
            $documentId,
            $priority,
            $maxAttempts,
        );
    }

    /**
     * Uebernimmt den naechsten faelligen Job exklusiv.
     *
     * Die Auswahl und das Setzen des Lease laufen in einer Transaktion mit
     * Zeilensperre. Ein zweiter Lauf sieht den Job danach nicht mehr.
     */
    public function claim(string $owner, ?string $jobType = null): ?ProcessingJob
    {
        return DB::transaction(function () use ($owner, $jobType): ?ProcessingJob {
            $query = ProcessingJob::query()
                ->claimable()
                ->orderBy('priority')
                ->orderBy('available_at')
                ->orderBy('id')
                ->lockForUpdate();

            if ($jobType !== null) {
                $query->where('job_type', $jobType);
            }

            $job = $query->first();

            if (! $job instanceof ProcessingJob) {
                return null;
            }

            $now = Carbon::now();

            $job->forceFill([
                'status' => ProcessingJobStatus::GELEAST,
                'lease_owner' => $owner,
                'leased_until' => $now->copy()->addSeconds($this->leaseSeconds),
                'heartbeat_at' => $now,
                'attempts' => (int) $job->getAttribute('attempts') + 1,
                'started_at' => $job->getAttribute('started_at') ?? $now,
            ])->save();

            return $job;
        });
    }

    /**
     * Verlaengert das Lease waehrend der Verarbeitung.
     *
     * @return bool false, wenn das Lease inzwischen einem anderen Lauf gehoert
     */
    public function heartbeat(ProcessingJob $job, string $owner): bool
    {
        $now = Carbon::now();

        $affected = ProcessingJob::query()
            ->whereKey($job->getKey())
            ->where('lease_owner', $owner)
            ->where('status', ProcessingJobStatus::GELEAST->value)
            ->update([
                'heartbeat_at' => $now,
                'leased_until' => $now->copy()->addSeconds($this->leaseSeconds),
                'updated_at' => $now,
            ]);

        if ($affected === 1) {
            $job->forceFill([
                'heartbeat_at' => $now,
                'leased_until' => $now->copy()->addSeconds($this->leaseSeconds),
            ]);
        }

        return $affected === 1;
    }

    public function succeed(ProcessingJob $job): void
    {
        $job->forceFill([
            'status' => ProcessingJobStatus::ERFOLGREICH,
            'lease_owner' => null,
            'leased_until' => null,
            'finished_at' => Carbon::now(),
            'error_code' => null,
            'last_error' => null,
        ])->save();
    }

    /**
     * Meldet einen Fehlversuch.
     *
     * Ein endgueltiger Fehler oder ein erschoepfter Versuchszaehler fuehrt in
     * den Dead-Letter-Status. Sonst wird der Job mit exponentiellem Backoff
     * erneut eingeplant.
     */
    public function fail(ProcessingJob $job, UploadErrorCode $errorCode, bool $permanent = false): ProcessingJobStatus
    {
        $attempts = (int) $job->getAttribute('attempts');
        $maxAttempts = (int) $job->getAttribute('max_attempts');

        $exhausted = $attempts >= $maxAttempts;
        $status = $permanent || $exhausted
            ? ProcessingJobStatus::DEAD_LETTER
            : ProcessingJobStatus::BEREIT;

        $now = Carbon::now();

        $job->forceFill([
            'status' => $status,
            'lease_owner' => null,
            'leased_until' => null,
            'available_at' => $status === ProcessingJobStatus::BEREIT
                ? $now->copy()->addSeconds($this->backoff->delayFor($attempts))
                : $job->getAttribute('available_at'),
            'finished_at' => $status === ProcessingJobStatus::DEAD_LETTER ? $now : null,
            'error_code' => $errorCode->value,
            'last_error' => $errorCode->message(),
        ])->save();

        if ($status === ProcessingJobStatus::DEAD_LETTER) {
            $this->deadLetterListener?->deadLettered($job);
        }

        return $status;
    }

    /**
     * Gibt einen Job ohne Fehlermeldung zurueck in die Warteschlange, zum
     * Beispiel wenn ein Cron-Lauf seine Restlaufzeit erreicht hat. Der
     * Versuchszaehler wird dabei zurueckgenommen, weil kein Versuch
     * stattgefunden hat.
     */
    public function release(ProcessingJob $job): void
    {
        $job->forceFill([
            'status' => ProcessingJobStatus::BEREIT,
            'lease_owner' => null,
            'leased_until' => null,
            'attempts' => max(0, (int) $job->getAttribute('attempts') - 1),
            'available_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Holt Jobs zurueck, deren Lease abgelaufen ist. Das ist der
     * Wiederanlaufpfad nach einem Worker- oder Prozessabbruch mitten im Job.
     *
     * @return int Anzahl der zurueckgeholten Jobs
     */
    public function reclaimExpiredLeases(): int
    {
        $now = Carbon::now();

        $expired = ProcessingJob::query()
            ->where('status', ProcessingJobStatus::GELEAST->value)
            ->whereNotNull('leased_until')
            ->where('leased_until', '<=', $now)
            ->get();

        $reclaimed = 0;

        foreach ($expired as $job) {
            $attempts = (int) $job->getAttribute('attempts');
            $maxAttempts = (int) $job->getAttribute('max_attempts');

            if ($attempts >= $maxAttempts) {
                $job->forceFill([
                    'status' => ProcessingJobStatus::DEAD_LETTER,
                    'lease_owner' => null,
                    'leased_until' => null,
                    'finished_at' => $now,
                    'error_code' => UploadErrorCode::LEASE_ABGELAUFEN->value,
                    'last_error' => UploadErrorCode::LEASE_ABGELAUFEN->message(),
                ])->save();

                // Der Uebergang nach DEAD_LETTER ist endgueltig: das Dokument
                // wird abgeschlossen und die Quelldaten werden geloescht.
                $this->deadLetterListener?->deadLettered($job);
            } else {
                $job->forceFill([
                    'status' => ProcessingJobStatus::BEREIT,
                    'lease_owner' => null,
                    'leased_until' => null,
                    'available_at' => $now->copy()->addSeconds($this->backoff->delayFor($attempts)),
                    'error_code' => UploadErrorCode::LEASE_ABGELAUFEN->value,
                    'last_error' => UploadErrorCode::LEASE_ABGELAUFEN->message(),
                ])->save();
            }

            $reclaimed++;
        }

        return $reclaimed;
    }

    /**
     * Bricht alle offenen Jobs eines Dokuments ab. Wird vom TTL-Cleanup
     * genutzt, damit ein Job nach der Loeschung nicht ins Leere laeuft.
     *
     * @return int Anzahl der abgebrochenen Jobs
     */
    public function cancelForDocument(string $documentId): int
    {
        return ProcessingJob::query()
            ->where('document_id', $documentId)
            ->whereIn('status', [ProcessingJobStatus::BEREIT->value, ProcessingJobStatus::GELEAST->value])
            ->update([
                'status' => ProcessingJobStatus::ABGEBROCHEN->value,
                'lease_owner' => null,
                'leased_until' => null,
                'finished_at' => Carbon::now(),
                'error_code' => UploadErrorCode::TTL_ABGELAUFEN->value,
                'last_error' => UploadErrorCode::TTL_ABGELAUFEN->message(),
            ]);
    }

    /**
     * Erzeugt eine Laufkennung fuer den Lease-Inhaber. Sie enthaelt bewusst
     * keinen Nutzer- und keinen Mandantenbezug.
     */
    public function newOwnerToken(string $prefix = 'cron'): string
    {
        return $prefix.'-'.Str::lower((string) Str::ulid());
    }

    public function leaseSeconds(): int
    {
        return $this->leaseSeconds;
    }
}
