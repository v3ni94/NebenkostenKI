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
 * VORGEHEN: Liegt ein Cache vor, wird er geloescht und der Befehl mit
 * denselben Argumenten in einem frischen PHP-Prozess erneut gestartet. Ein
 * Neuladen innerhalb des laufenden Prozesses reicht nicht, weil die
 * Konfiguration bereits beim Bootstrap aus dem Cache in den Speicher gelesen
 * wurde. Der Kindprozess erbt die Umgebung und arbeitet im Projektverzeichnis.
 *
 * Der Cache wird am Ende von smartabrechnen:install ohnehin neu erzeugt,
 * sodass die Anwendung im Betrieb weiterhin mit Cache laeuft.
 */
trait RefreshesCachedConfiguration
{
    /**
     * Gibt den Exit-Code des Neustarts zurueck, wenn ein Cache vorlag, sonst
     * null. Der Aufrufer beendet sich im ersten Fall mit diesem Code.
     */
    protected function ensureFreshConfiguration(): ?int
    {
        if (! $this->laravel->configurationIsCached()) {
            return null;
        }

        if (getenv('SMARTABRECHNEN_CONFIG_REFRESHED') === '1') {
            // Zweiter Durchlauf und trotzdem ein Cache: nicht endlos neu
            // starten, sondern mit der vorhandenen Konfiguration weiterarbeiten.
            return null;
        }

        $this->line('  [INFO] Konfigurationscache gefunden. Er wird geleert, damit gegen die aktuelle .env geprueft wird.');

        Artisan::call('config:clear');

        $process = new Process(
            array_merge([PHP_BINARY, base_path('artisan')], $this->originalArguments()),
            base_path(),
            ['SMARTABRECHNEN_CONFIG_REFRESHED' => '1'],
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
