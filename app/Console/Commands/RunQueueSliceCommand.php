<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DocumentJobRegistry;
use App\Services\Queue\DatabaseJobQueue;
use App\Services\Queue\QueueSliceRunner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;

/**
 * Begrenzter Lauf der datenbankgestuetzten Queue (ADR-006, Abschnitt 3.5).
 *
 * Auf IONOS Profil A ruft ein Cronjob schedule:run auf, der Scheduler startet
 * diesen Befehl mit begrenzter Laufzeit. Ein dauerhafter Worker und Redis
 * werden nicht vorausgesetzt. Der Lauf beendet sich selbst vor Ablauf der
 * Restzeit und stellt einen begonnenen Teiljob unveraendert zurueck.
 */
final class RunQueueSliceCommand extends Command
{
    protected $signature = 'smartabrechnen:queue-slice
        {--max-time=45 : Restlaufzeit des Laufs in Sekunden}
        {--max-jobs=100 : Hoechstzahl der Teiljobs in einem Lauf}';

    protected $description = 'Verarbeitet fällige Teiljobs mit Lease, Heartbeat und exponentiellem Backoff.';

    public function handle(
        DatabaseJobQueue $queue,
        DocumentJobRegistry $registry,
        Container $container,
    ): int {
        $maxTime = (float) $this->option('max-time');
        $maxJobs = (int) $this->option('max-jobs');

        $runner = new QueueSliceRunner($queue, $registry->make(), $container);

        $report = $runner->run(
            $queue->newOwnerToken('cron'),
            $maxTime > 0 ? $maxTime : 45.0,
            $maxJobs > 0 ? $maxJobs : 100,
        );

        $this->line($report->summary());

        foreach ($report->errorCodes as $code => $count) {
            $this->warn(sprintf('Fehlercode %s: %dmal.', $code, $count));
        }

        return self::SUCCESS;
    }
}
