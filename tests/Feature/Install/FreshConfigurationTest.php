<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Installations- und Pruefbefehle muessen gegen die aktuelle .env arbeiten, auch
 * wenn ein veralteter Konfigurationscache vorliegt.
 *
 * Nachgestellt wird der Fall aus dem Nachtest: Der Cache enthaelt noch einen
 * APP_KEY, die Umgebung nicht mehr. Ohne Auffrischung meldet der Befehl
 * faelschlich OK. Mit Auffrischung erkennt er den fehlenden Schluessel.
 *
 * Der Nachweis laeuft in einem Kindprozess, weil nur ein frischer Prozess den
 * Cache beim Bootstrap tatsaechlich liest.
 */
final class FreshConfigurationTest extends TestCase
{
    private string $arbeit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arbeit = storage_path('framework/testing/fresh-config-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->arbeit.'/cache');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->arbeit);

        parent::tearDown();
    }

    public function test_install_erkennt_fehlenden_app_key_trotz_veraltetem_cache(): void
    {
        $datenbank = $this->arbeit.'/install.sqlite';
        touch($datenbank);

        $umgebung = $this->umgebung($datenbank);

        // 1. Cache mit gueltigem APP_KEY erzeugen (so wie ein frueherer Lauf).
        $mitKey = $umgebung + ['APP_KEY' => 'base64:'.base64_encode(random_bytes(32))];
        $this->prozess(['config:cache'], $mitKey)->mustRun();
        self::assertFileExists($this->arbeit.'/cache/config.php', 'Vorbedingung: Cache muss vorliegen.');

        // 2. APP_KEY aus der Umgebung entfernen, Cache bleibt liegen.
        $ohneKey = $umgebung + ['APP_KEY' => ''];
        $prozess = $this->prozess(['smartabrechnen:install', '--no-interaction', '--skip-blockers'], $ohneKey);
        $prozess->run();

        $ausgabe = $prozess->getOutput().$prozess->getErrorOutput();

        self::assertSame(1, $prozess->getExitCode(), "Der Befehl haette wegen fehlendem APP_KEY scheitern muessen.\n".$ausgabe);
        self::assertStringContainsString('Konfigurationscache gefunden', $ausgabe);
        self::assertStringContainsString('APP_KEY ist nicht gesetzt', $ausgabe);
    }

    public function test_check_config_prueft_gegen_die_aktuelle_env_und_laesst_den_cache_unangetastet(): void
    {
        $datenbank = $this->arbeit.'/check.sqlite';
        touch($datenbank);

        $umgebung = $this->umgebung($datenbank) + ['APP_KEY' => 'base64:'.base64_encode(random_bytes(32))];

        $this->prozess(['migrate', '--force', '--no-interaction'], $umgebung)->mustRun();

        // 1. Cache mit abgeschaltetem Debugmodus erzeugen (so wie install).
        $this->prozess(['config:cache'], $umgebung)->mustRun();
        $cacheDatei = $this->arbeit.'/cache/config.php';
        self::assertFileExists($cacheDatei, 'Vorbedingung: Cache muss vorliegen.');
        $cacheInhalt = (string) file_get_contents($cacheDatei);

        // 2. Die .env aendert sich nach dem Cachen: APP_DEBUG=true. Der Cache
        //    kennt weiterhin false. Die Pruefung muss den neuen Wert sehen.
        $prozess = $this->prozess(['smartabrechnen:check-config'], array_replace($umgebung, ['APP_DEBUG' => 'true']));
        $prozess->run();

        $ausgabe = $prozess->getOutput().$prozess->getErrorOutput();

        self::assertStringContainsString('Konfigurationscache gefunden', $ausgabe);
        // Nach dem Neustart darf der Hinweis nicht ein zweites Mal erscheinen.
        self::assertSame(1, substr_count($ausgabe, 'Konfigurationscache gefunden'), $ausgabe);
        self::assertStringContainsString('Der Debugmodus ist eingeschaltet', $ausgabe, 'check-config muss gegen die aktuelle .env pruefen, nicht gegen den Cache.');

        // 3. Der produktive Cache bleibt unveraendert liegen. Ohne ihn wuerde
        //    die Web-Anwendung bis zum naechsten install ohne Cache laufen.
        self::assertFileExists($cacheDatei, 'check-config darf den produktiven Konfigurationscache nicht loeschen.');
        self::assertSame($cacheInhalt, (string) file_get_contents($cacheDatei), 'check-config darf den Cache nicht veraendern.');

        // 4. Es bleibt keine Hilfsdatei des Pruefprozesses zurueck.
        self::assertSame([], glob(storage_path('framework/cache/config-pruefung-*.php')) ?: []);
    }

    /**
     * @return array<string, string>
     */
    private function umgebung(string $datenbank): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://smart-abrechnen.test',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $datenbank,
            'FILESYSTEM_DISK' => 'local',
            'MAIL_MAILER' => 'array',
            'AI_PRIMARY_PROVIDER' => 'fake',
            'AI_FALLBACK_ENABLED' => 'false',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'APP_CONFIG_CACHE' => $this->arbeit.'/cache/config.php',
            'APP_ROUTES_CACHE' => $this->arbeit.'/cache/routes.php',
            'APP_EVENTS_CACHE' => $this->arbeit.'/cache/events.php',
            'APP_SERVICES_CACHE' => $this->arbeit.'/cache/services.php',
            'APP_PACKAGES_CACHE' => $this->arbeit.'/cache/packages.php',
            'PATH' => (string) getenv('PATH'),
        ];
    }

    /**
     * @param  list<string>  $argumente
     * @param  array<string, string>  $umgebung
     */
    private function prozess(array $argumente, array $umgebung): Process
    {
        $prozess = new Process(array_merge([PHP_BINARY, base_path('artisan')], $argumente), base_path(), $umgebung);
        $prozess->setTimeout(120);

        return $prozess;
    }
}
