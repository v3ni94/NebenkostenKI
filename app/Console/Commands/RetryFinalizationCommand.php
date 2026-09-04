<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Payment\IssueMissingInvoice;
use App\Application\Payment\RetryFinalization;
use Illuminate\Console\Command;

/**
 * Holt nach bestaetigter Zahlung liegen gebliebene Leistungen nach.
 *
 *  1. Erneute Finalisierung bezahlter Laeufe in FAILED oder haengend in PAID.
 *  2. Nachgeholte Rechnung fuer bezahlte, finalisierte Laeufe ohne Rechnung,
 *     sobald die Betreiberstammdaten bestaetigt sind.
 *
 * Der Befehl laeuft regelmaessig ueber den Zeitplan und ist idempotent: Ein
 * erfolgreich nachgeholter Lauf erscheint im naechsten Lauf nicht mehr, ein
 * weiterhin gescheiterter bleibt sichtbar. Der Adminbereich zeigt dieselben
 * Faelle unter Zahlungsnachlauf.
 */
final class RetryFinalizationCommand extends Command
{
    protected $signature = 'smartabrechnen:retry-finalization
        {--batch=25 : Hoechstzahl der je Fallart in einem Lauf nachgeholten Laeufe}';

    protected $description = 'Holt Finalisierung und Rechnung für bezahlte Abrechnungsläufe nach.';

    public function handle(RetryFinalization $retry, IssueMissingInvoice $invoices): int
    {
        $batch = (int) $this->option('batch');
        $batch = $batch > 0 ? $batch : 25;

        $finalization = $retry->all($batch);
        $this->line($finalization->summary('Finalisierung nachgeholt'));

        $invoicing = $invoices->all($batch);
        $this->line($invoicing->summary('Rechnung nachgeholt'));

        $failures = $finalization->failures() + $invoicing->failures();

        foreach ($failures as $id => $message) {
            $this->warn(sprintf('%s: %s', $id, $message));
        }

        if ($failures !== []) {
            $this->warn('Offene Fälle bleiben im Adminbereich unter Zahlungsnachlauf sichtbar.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
