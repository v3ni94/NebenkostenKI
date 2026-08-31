<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Documents\RetryFailedDeletions;
use Illuminate\Console\Command;

/**
 * Wiederholt fehlgeschlagene Loeschungen von Quelldaten.
 *
 * Eine fehlgeschlagene Loeschung bedeutet, dass eine Originaldatei laenger im
 * Kurzzeitbereich liegt, als das Loeschkonzept es zulaesst. Der Befehl laeuft
 * deshalb regelmaessig ueber den Scheduler; der Adminbereich zeigt offene
 * Faelle zusaetzlich als kritischen Datenschutzalarm (Abschnitt 19).
 */
final class RetryFailedDeletionsCommand extends Command
{
    protected $signature = 'smartabrechnen:retry-failed-deletions
        {--batch=50 : Hoechstzahl der in einem Lauf wiederholten Loeschungen}';

    protected $description = 'Wiederholt fehlgeschlagene Löschungen von Originaldateien und Providerdateien.';

    public function handle(RetryFailedDeletions $retry): int
    {
        $batch = (int) $this->option('batch');

        $report = $retry($batch > 0 ? $batch : RetryFailedDeletions::DEFAULT_BATCH_SIZE);

        $this->line($report->summary());

        $open = $retry->openAlertCount();

        if ($open > 0) {
            $this->warn(sprintf(
                'Offene Datenschutzalarme: %d. Bitte im Adminbereich prüfen.',
                $open
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
