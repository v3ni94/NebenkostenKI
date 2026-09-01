<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Reminder\SendReminders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Versendet die automatischen Erinnerungen des Tages (Masterprompt 17).
 *
 * Der Befehl laeuft taeglich ueber den Scheduler. Er prueft selbst, ob der
 * heutige Tag in Europe/Berlin ein Erinnerungstermin ist. Faellt kein Termin an,
 * endet er ohne Wirkung.
 *
 * IDEMPOTENZ: Der Lauf ist bewusst mehrfach ausfuehrbar. Die Dublettensperre
 * liegt im eindeutigen deduplication_key der Tabelle reminder_events und nicht
 * nur in diesem Befehl. Ein zweiter Cronlauf am selben Tag erzeugt deshalb
 * keine zweite Mail.
 *
 * Die Option --date dient dem Betrieb und den Tests. Sie verschiebt nur den
 * fachlichen Stichtag, nicht die Systemzeit.
 */
final class SendRemindersCommand extends Command
{
    protected $signature = 'smartabrechnen:send-reminders
        {--date= : Fachlicher Stichtag im Format JJJJ-MM-TT, Standard ist heute}
        {--limit=0 : Hoechstzahl der in einem Lauf gepruefften Objekte, 0 bedeutet ohne Begrenzung}';

    protected $description = 'Versendet die automatischen Erinnerungen für Folgejahre.';

    public function handle(SendReminders $erinnerungen): int
    {
        $datum = $this->option('date');
        $limit = (int) $this->option('limit');

        $stichtag = is_string($datum) && $datum !== ''
            ? CarbonImmutable::parse($datum, 'Europe/Berlin')->startOfDay()
            : null;

        $bericht = $erinnerungen->fuerTag($stichtag, $limit > 0 ? $limit : null);

        $this->line($bericht->zusammenfassung());

        return self::SUCCESS;
    }
}
