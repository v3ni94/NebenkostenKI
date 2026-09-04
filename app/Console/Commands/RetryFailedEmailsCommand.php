<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EmailStatus;
use App\Mail\MailDispatcher;
use App\Mail\WiederholungNichtMoeglichException;
use App\Models\EmailMessage;
use Illuminate\Console\Command;
use Throwable;

/**
 * Versendet zeitweilig gescheiterte Transaktionsmails erneut.
 *
 * Ausgewaehlt werden Nachrichten im Status FEHLGESCHLAGEN mit vorhandenem
 * Wiederholungspuffer, weniger als MailDispatcher::MAX_VERSUCHE Versuchen und
 * innerhalb von MailDispatcher::WIEDERHOLUNGSFENSTER_STUNDEN. Dauerhaft
 * unzustellbare (BOUNCED) und unterdrueckte Nachrichten werden nie wiederholt.
 *
 * Nachrichten ausserhalb des Fensters verlieren ihren Wiederholungspuffer,
 * damit kein Downloadlink laenger als noetig gespeichert bleibt. Der Befehl
 * laeuft regelmaessig ueber den Zeitplan und ist idempotent.
 */
final class RetryFailedEmailsCommand extends Command
{
    protected $signature = 'smartabrechnen:retry-failed-emails
        {--batch=25 : Hoechstzahl der in einem Lauf erneut versendeten Nachrichten}';

    protected $description = 'Versendet zeitweilig gescheiterte Transaktionsmails erneut.';

    public function handle(MailDispatcher $mailer): int
    {
        $batch = (int) $this->option('batch');
        $batch = $batch > 0 ? $batch : 25;

        $frist = now()->subHours(MailDispatcher::WIEDERHOLUNGSFENSTER_STUNDEN);

        $bereinigt = EmailMessage::query()
            ->whereNotNull('retry_payload')
            ->where(function ($query) use ($frist): void {
                $query
                    ->where('queued_at', '<', $frist)
                    ->orWhere('status', '!=', EmailStatus::FEHLGESCHLAGEN->value)
                    ->orWhere('attempts', '>=', MailDispatcher::MAX_VERSUCHE);
            })
            ->update(['retry_payload' => null]);

        /** @var list<EmailMessage> $nachrichten */
        $nachrichten = EmailMessage::query()
            ->with('user')
            ->where('status', EmailStatus::FEHLGESCHLAGEN->value)
            ->whereNotNull('retry_payload')
            ->where('attempts', '<', MailDispatcher::MAX_VERSUCHE)
            ->where('queued_at', '>=', $frist)
            ->orderBy('failed_at')
            ->limit($batch)
            ->get()
            ->all();

        $gesendet = 0;
        $weiterhinOffen = 0;
        $uebersprungen = 0;

        foreach ($nachrichten as $nachricht) {
            try {
                $ergebnis = $mailer->erneutSenden($nachricht);
            } catch (WiederholungNichtMoeglichException $ausnahme) {
                $uebersprungen++;
                $this->line(sprintf('%s: %s', (string) $nachricht->getKey(), $ausnahme->getMessage()));

                continue;
            } catch (Throwable $ausnahme) {
                // Ein unerwarteter Fehler einer Nachricht darf die uebrigen
                // nicht aufhalten. Der Fehler wird als Klasse gemeldet, nie
                // mit Zugangsdaten.
                $weiterhinOffen++;
                $this->warn(sprintf('%s: %s', (string) $nachricht->getKey(), $ausnahme::class));

                continue;
            }

            if ($ergebnis->getAttribute('status') === EmailStatus::GESENDET) {
                $gesendet++;
            } elseif ($ergebnis->getAttribute('status') === EmailStatus::FEHLGESCHLAGEN) {
                $weiterhinOffen++;
            } else {
                $uebersprungen++;
            }
        }

        $this->line(sprintf(
            'Erneut versendet: %d, weiterhin offen: %d, nicht wiederholt: %d, Puffer bereinigt: %d.',
            $gesendet,
            $weiterhinOffen,
            $uebersprungen,
            $bereinigt,
        ));

        if ($weiterhinOffen > 0) {
            $this->warn('Offene Nachrichten bleiben im Adminbereich unter Kommunikation sichtbar.');
        }

        return self::SUCCESS;
    }
}
