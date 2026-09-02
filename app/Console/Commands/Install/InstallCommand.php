<?php

declare(strict_types=1);

namespace App\Console\Commands\Install;

use App\Application\Admin\LaunchBlockerCheck;
use App\Application\Install\EnvironmentRequirements;
use App\Application\Install\StorageDirectories;
use App\Console\Commands\Install\Concerns\RefreshesCachedConfiguration;
use App\Models\CostCategory;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Inbetriebnahme und Aktualisierung auf dem Zielsystem (Masterprompt 21.2,
 * docs/betrieb/installation.md).
 *
 * Der Befehl ist idempotent und wiederaufnehmbar. Er laeuft ohne Rueckfrage,
 * weil er auf IONOS ueber einen Cronjob des Control-Centers gestartet wird und
 * dort niemand antworten kann. Bricht eine Voraussetzung, endet der Lauf mit
 * Exit-Code 1 und einer klaren Meldung, bevor irgendetwas veraendert wird.
 *
 * Ablauf:
 *   1. Umgebung pruefen (PHP, Erweiterungen, Schreibrechte, APP_KEY)
 *   2. Speicherverzeichnisse anlegen
 *   3. Migrationen ausfuehren
 *   4. Kategorien einspielen, nur wenn noch keine vorhanden sind
 *   5. Produktionscaches erzeugen (config, route, view, event)
 *   6. Livegang-Blocker ausgeben
 *
 * Es wird KEIN oeffentlicher Speicherlink angelegt: Die Anwendung liefert
 * keine Dateien ueber public/storage aus.
 */
final class InstallCommand extends Command
{
    use RefreshesCachedConfiguration;

    protected $signature = 'smartabrechnen:install
        {--no-cache : Produktionscaches nicht erzeugen (nur fuer Entwicklung und Tests)}
        {--skip-blockers : Livegang-Blocker nicht ausgeben}';

    protected $description = 'Richtet die Anwendung auf dem Zielsystem ein oder aktualisiert sie (idempotent).';

    public function handle(StorageDirectories $storage, LaunchBlockerCheck $blockers): int
    {
        $restarted = $this->ensureFreshConfiguration();

        if ($restarted !== null) {
            return $restarted;
        }

        $this->line('Smart Abrechnen: Inbetriebnahme');
        $this->newLine();

        // --- 1. Umgebung ------------------------------------------------------
        $requirements = new EnvironmentRequirements(
            [storage_path(), base_path('bootstrap/cache')],
            $this->configString('app.key'),
        );

        $failed = false;

        foreach ($requirements->check() as $result) {
            $this->line(sprintf('  [%s] %s: %s', $result->fulfilled ? 'OK' : 'FEHLER', $result->name, $result->message));
            $failed = $failed || ! $result->fulfilled;
        }

        if ($failed) {
            $this->newLine();
            $this->error('Die Inbetriebnahme wurde abgebrochen. Bitte beheben Sie die oben genannten Voraussetzungen und starten Sie den Befehl erneut.');

            return self::FAILURE;
        }

        // --- 2. Speicherverzeichnisse ----------------------------------------
        try {
            $created = $storage->ensure(storage_path());
        } catch (Throwable $exception) {
            $this->error('Speicherverzeichnisse: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('  [OK] Speicherverzeichnisse: %s', $created === [] ? 'vollstaendig vorhanden.' : count($created).' angelegt.'));

        // --- 3. Migrationen ---------------------------------------------------
        if ($this->runArtisan('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error('Die Migrationen sind fehlgeschlagen. Bitte pruefen Sie die Datenbankverbindung (DB_*) und starten Sie den Befehl erneut; bereits ausgefuehrte Migrationen werden nicht wiederholt.');

            return self::FAILURE;
        }

        $this->line('  [OK] Migrationen: auf aktuellem Stand.');

        // --- 4. Kategorien ----------------------------------------------------
        try {
            $count = CostCategory::query()->count();
        } catch (Throwable $exception) {
            $this->error('Die Kategorien konnten nicht gelesen werden ('.class_basename($exception).').');

            return self::FAILURE;
        }

        if ($count === 0) {
            if ($this->runArtisan('db:seed', ['--class' => CostCategorySeeder::class, '--force' => true]) !== self::SUCCESS) {
                $this->error('Die Kategorien konnten nicht eingespielt werden.');

                return self::FAILURE;
            }

            $this->line(sprintf('  [OK] Kategorien: %d eingespielt.', CostCategory::query()->count()));
        } else {
            $this->line(sprintf('  [OK] Kategorien: %d vorhanden, kein Seed erforderlich.', $count));
        }

        // --- 5. Caches --------------------------------------------------------
        if ($this->option('no-cache') === true) {
            $this->line('  [--] Caches: uebersprungen (--no-cache).');
        } else {
            foreach (['config:cache', 'route:cache', 'view:cache', 'event:cache'] as $command) {
                if ($this->runArtisan($command) !== self::SUCCESS) {
                    $this->error(sprintf('%s ist fehlgeschlagen. Bitte pruefen Sie die Schreibrechte auf bootstrap/cache und storage/framework/views.', $command));

                    return self::FAILURE;
                }
            }

            $this->line('  [OK] Caches: config, route, view und event erzeugt.');
        }

        // --- 6. Livegang-Blocker ----------------------------------------------
        if ($this->option('skip-blockers') !== true) {
            $this->newLine();
            $report = $blockers->report();

            if ($report->isClear()) {
                $this->info('Livegang-Blocker: keine.');
            } else {
                $this->warn(sprintf('Livegang-Blocker: %d (%d blockierend).', $report->count(), $report->blockingCount()));

                foreach ($report->blockers as $blocker) {
                    $this->line(sprintf('  - [%s] %s: %s Verantwortlich: %s', $blocker->severity, $blocker->area, $blocker->missing, $blocker->responsible));
                }
            }
        }

        $this->newLine();
        $this->info('Die Inbetriebnahme ist abgeschlossen. Naechste Schritte: smartabrechnen:admin:create und smartabrechnen:check-config.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runArtisan(string $command, array $parameters = []): int
    {
        try {
            $code = Artisan::call($command, $parameters, $this->output);
        } catch (Throwable $exception) {
            $this->error(sprintf('%s: %s', $command, class_basename($exception)));

            return self::FAILURE;
        }

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) ? $value : null;
    }
}
