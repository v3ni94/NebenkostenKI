<?php

declare(strict_types=1);

namespace App\Console\Commands\Install;

use App\Application\Install\CheckResult;
use App\Application\Install\ConfigurationCheck;
use App\Console\Commands\Install\Concerns\RefreshesCachedConfiguration;
use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Prueft die hinterlegten Zugangsdaten tatsaechlich, ohne Geheimnisse
 * auszugeben (Masterprompt 20, 22, 26).
 *
 * Ausgabe als Tabelle mit OK, WARNUNG oder FEHLER und je Zeile einer
 * konkreten Handlungsanweisung. Exit-Code 1 bei mindestens einem FEHLER.
 */
final class CheckConfigCommand extends Command
{
    use RefreshesCachedConfiguration;

    protected $signature = 'smartabrechnen:check-config
        {--send-test-mail= : Sendet zusaetzlich eine Testnachricht an diese Adresse}';

    protected $description = 'Prueft Datenbank, SFTP, SMTP, Stripe, KI-Provider, Assets, Cronjob und Sicherheitseinstellungen.';

    public function handle(ConfigurationCheck $check): int
    {
        $restarted = $this->ensureFreshConfiguration();

        if ($restarted !== null) {
            return $restarted;
        }

        $results = $check->run();

        $testMail = $this->option('send-test-mail');

        if (is_string($testMail) && trim($testMail) !== '') {
            $results[] = $this->sendTestMail(trim($testMail));
        }

        $this->table(
            ['Pruefung', 'Status', 'Ergebnis', 'Handlung'],
            array_map(
                static fn (CheckResult $result): array => [
                    $result->name,
                    $result->status,
                    wordwrap($result->message, 60, "\n", true),
                    wordwrap($result->action, 60, "\n", true),
                ],
                $results,
            ),
        );

        $errors = count(array_filter($results, static fn (CheckResult $result): bool => $result->isError()));
        $warnings = count(array_filter($results, static fn (CheckResult $result): bool => $result->status === CheckResult::WARNUNG));

        if ($errors > 0) {
            $this->error(sprintf('%d Fehler, %d Warnungen. Bitte beheben Sie die Fehler vor dem Livegang.', $errors, $warnings));

            return self::FAILURE;
        }

        if ($warnings > 0) {
            $this->warn(sprintf('Keine Fehler, %d Warnungen.', $warnings));
        } else {
            $this->info('Alle Pruefungen erfolgreich.');
        }

        return self::SUCCESS;
    }

    private function sendTestMail(string $address): CheckResult
    {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return CheckResult::error('Testmail', 'Die angegebene Adresse ist ungueltig.', 'Eine gueltige Empfaengeradresse angeben.');
        }

        try {
            Mail::raw(
                "Diese Nachricht wurde von smartabrechnen:check-config versendet.\n\n"
                .'Kommt sie an, sind Postausgangsserver und Absenderadresse korrekt konfiguriert.',
                static function (Message $message) use ($address): void {
                    $message->to($address)->subject('Smart Abrechnen: Testnachricht der Konfigurationspruefung');
                },
            );
        } catch (Throwable $exception) {
            return CheckResult::error(
                'Testmail',
                'Der Versand ist fehlgeschlagen ('.class_basename($exception).').',
                'MAIL_*-Variablen pruefen. Die Absenderadresse muss zum Postfach gehoeren.',
            );
        }

        return CheckResult::ok('Testmail', 'Die Testnachricht wurde an den Postausgangsserver uebergeben.', 'Eingang im Postfach pruefen, auch im Spamordner.');
    }
}
