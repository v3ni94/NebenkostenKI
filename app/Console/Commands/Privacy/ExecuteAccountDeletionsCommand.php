<?php

declare(strict_types=1);

namespace App\Console\Commands\Privacy;

use App\Application\Privacy\AccountDeletionWorkflow;
use App\Application\Privacy\ExecuteAccountDeletion;
use Illuminate\Console\Command;
use Throwable;

/**
 * Führt fällige Kontolöschungen aus (Masterprompt 19).
 *
 * Der Lauf ist idempotent und wiederaufnehmbar: Er wählt nur Konten, deren
 * Frist abgelaufen ist und deren Antrag noch offen steht. Ein Fehlschlag bei
 * einem Konto bricht den Lauf nicht ab; das betroffene Konto bleibt fällig und
 * wird im nächsten Lauf erneut behandelt.
 */
final class ExecuteAccountDeletionsCommand extends Command
{
    protected $signature = 'smartabrechnen:execute-account-deletions
        {--batch=25 : Höchstzahl der in einem Lauf behandelten Konten}';

    protected $description = 'Führt beantragte Kontolöschungen nach Ablauf der Frist endgültig aus.';

    public function handle(
        AccountDeletionWorkflow $workflow,
        ExecuteAccountDeletion $execute,
    ): int {
        $batch = (int) $this->option('batch');
        $faellig = $workflow->due($batch > 0 ? $batch : 25);

        if ($faellig === []) {
            $this->line('Keine fälligen Kontolöschungen.');

            return self::SUCCESS;
        }

        $ausgefuehrt = 0;
        $fehler = 0;

        foreach ($faellig as $nutzer) {
            $referenz = (string) $nutzer->getKey();

            try {
                $bericht = $execute($nutzer);

                if ($bericht->executed) {
                    $ausgefuehrt++;
                }

                $this->line($referenz.': '.$bericht->summary());
            } catch (Throwable $fehlschlag) {
                $fehler++;
                $this->warn(sprintf(
                    '%s: Die Löschung ist fehlgeschlagen und wird im nächsten Lauf wiederholt. Grund: %s',
                    $referenz,
                    $fehlschlag->getMessage(),
                ));
            }
        }

        $this->line(sprintf('%d Konten gelöscht, %d Fehler.', $ausgefuehrt, $fehler));

        return $fehler > 0 ? self::FAILURE : self::SUCCESS;
    }
}
