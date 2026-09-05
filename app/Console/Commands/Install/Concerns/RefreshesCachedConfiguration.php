<?php

declare(strict_types=1);

namespace App\Console\Commands\Install\Concerns;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/**
 * Laesst einen Installations- oder Pruefbefehl immer gegen die aktuelle .env
 * laufen, nicht gegen einen veralteten Konfigurationscache.
 *
 * ANLASS: smartabrechnen:install erzeugt am Ende den Konfigurationscache. Ist er
 * vorhanden, ueberspringt Laravel beim naechsten Start das Laden der .env
 * vollstaendig. Ein spaeter nachgetragener Wert, zum Beispiel ein Stripe-
 * Schluessel, waere fuer die Pruefungen in smartabrechnen:check-config und fuer
 * die Voraussetzungspruefung in smartabrechnen:install unsichtbar, bis ein
 * weiterer Lauf den Cache neu schreibt. Der Betreiber saehe eine veraltete
 * Diagnose und wuesste nicht, warum. Genau das ist beim Nachtesten passiert:
 * ein entferntes APP_KEY wurde nicht erkannt, weil der Cache es noch enthielt.
 *
 * VORGEHEN: Liegt ein Cache vor, wird der Befehl mit denselben Argumenten in
 * einem frischen PHP-Prozess erneut gestartet. Ein Neuladen innerhalb des
 * laufenden Prozesses reicht nicht, weil die Konfiguration bereits beim
 * Bootstrap aus dem Cache in den Speicher gelesen wurde. Der Kindprozess erbt
 * die Umgebung und arbeitet im Projektverzeichnis.
 *
 * Zwei Spielarten:
 *
 *  - Ein Befehl, der den Cache am Ende selbst neu erzeugt
 *    (smartabrechnen:install), leert ihn vorher (config:clear). Die Anwendung
 *    laeuft danach wieder mit frischem Cache.
 *  - Ein reiner Pruefbefehl (smartabrechnen:check-config) darf den
 *    produktiven Cache nicht antasten: Ohne Cache liest die Web-Anwendung
 *    bei jeder Anfrage .env und alle config/*.php neu, bis irgendwann wieder
 *    smartabrechnen:install laeuft. Der Kindprozess erhaelt deshalb ueber
 *    APP_CONFIG_CACHE einen abweichenden, nicht vorhandenen Cache-Pfad. Er
 *    startet damit ohne Cache gegen die aktuelle .env, waehrend die Datei
 *    bootstrap/cache/config.php der Produktion unveraendert liegen bleibt.
 */
trait RefreshesCachedConfiguration
{
    /**
     * Gibt den Exit-Code des Neustarts zurueck, wenn ein Cache vorlag, sonst
     * null. Der Aufrufer beendet sich im ersten Fall mit diesem Code.
     *
     * @param  bool  $keepCache  true fuer reine Pruefbefehle, die den
     *                           produktiven Cache nicht loeschen duerfen.
     */
    protected function ensureFreshConfiguration(bool $keepCache = false): ?int
    {
        if (! $this->laravel->configurationIsCached()) {
            return null;
        }

        if (getenv('SMARTABRECHNEN_CONFIG_REFRESHED') === '1') {
            // Zweiter Durchlauf und trotzdem ein Cache: nicht endlos neu
            // starten, sondern mit der vorhandenen Konfiguration weiterarbeiten.
            return null;
        }

        $environment = ['SMARTABRECHNEN_CONFIG_REFRESHED' => '1'];

        if ($keepCache) {
            $this->line('  [INFO] Konfigurationscache gefunden. Die Pruefung laeuft in einem eigenen Prozess gegen die aktuelle .env, der Cache bleibt erhalten.');

            // Nicht vorhandene Datei: der Kindprozess sieht keinen Cache und
            // liest die .env. Der Pruefbefehl schreibt selbst keinen Cache,
            // die Datei entsteht also nie.
            $environment['APP_CONFIG_CACHE'] = $this->laravel->storagePath(
                'framework/cache/config-pruefung-'.getmypid().'-'.bin2hex(random_bytes(4)).'.php'
            );
        } else {
            $this->line('  [INFO] Konfigurationscache gefunden. Er wird geleert, damit gegen die aktuelle .env geprueft wird.');

            Artisan::call('config:clear');
        }

        $process = new Process(
            array_merge([PHP_BINARY, base_path('artisan')], $this->originalArguments()),
            base_path(),
            $environment,
        );
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? self::FAILURE;
    }

    /**
     * Die urspruenglichen Kommandozeilenargumente ohne den PHP-Aufruf und ohne
     * "artisan", damit der Neustart exakt denselben Befehl ausfuehrt.
     *
     * @return list<string>
     */
    private function originalArguments(): array
    {
        /** @var list<string> $argv */
        $argv = $_SERVER['argv'] ?? [];

        // $argv[0] ist "artisan"; alles danach ist der eigentliche Aufruf.
        $arguments = array_slice($argv, 1);

        if ($arguments === []) {
            return [$this->getName() ?? ''];
        }

        return array_values($arguments);
    }
}
