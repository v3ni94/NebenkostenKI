<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Fuehrt smartabrechnen:install in einem eigenen Prozess gegen eine
 * SQLite-Datei aus, so wie er auf dem Zielsystem laeuft.
 *
 * Die Cachepfade werden ueber APP_CONFIG_CACHE, APP_ROUTES_CACHE,
 * APP_EVENTS_CACHE und VIEW_COMPILED_PATH in ein Arbeitsverzeichnis gelenkt.
 * Damit erzeugt der Test echte Produktionscaches, ohne bootstrap/cache des
 * Projekts zu beruehren.
 */
final class InstallCommandTest extends TestCase
{
    private string $arbeit = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->arbeit = storage_path('framework/testing/install-'.Str::lower(Str::random(10)));
        File::ensureDirectoryExists($this->arbeit.'/cache');
        File::ensureDirectoryExists($this->arbeit.'/views');
    }

    protected function tearDown(): void
    {
        if ($this->arbeit !== '' && File::isDirectory($this->arbeit)) {
            File::deleteDirectory($this->arbeit);
        }

        parent::tearDown();
    }

    public function test_install_ist_idempotent_und_erzeugt_caches_gegen_sqlite(): void
    {
        $datenbank = $this->arbeit.'/install.sqlite';
        touch($datenbank);

        $erster = $this->artisanProzess(['smartabrechnen:install', '--no-interaction'], $datenbank);

        $this->assertSame(0, $erster->getExitCode(), $erster->getOutput().$erster->getErrorOutput());
        $ausgabe = $erster->getOutput();
        $this->assertStringContainsString('[OK] PHP-Version', $ausgabe);
        $this->assertStringContainsString('[OK] APP_KEY', $ausgabe);
        $this->assertStringContainsString('[OK] Migrationen', $ausgabe);
        $this->assertStringContainsString('eingespielt', $ausgabe);
        $this->assertStringContainsString('[OK] Caches', $ausgabe);
        $this->assertStringContainsString('Livegang-Blocker', $ausgabe);
        $this->assertStringContainsString('Inbetriebnahme ist abgeschlossen', $ausgabe);

        // Caches liegen im Arbeitsverzeichnis, nicht in bootstrap/cache.
        $this->assertFileExists($this->arbeit.'/cache/config.php');
        $this->assertFileExists($this->arbeit.'/cache/routes-v7.php');
        $this->assertFileExists($this->arbeit.'/cache/events.php');
        $this->assertNotEmpty(File::files($this->arbeit.'/views'));

        // Kategorien wurden eingespielt, kein oeffentlicher Speicherlink.
        $pdo = new PDO('sqlite:'.$datenbank);
        $anzahl = (int) $pdo->query('SELECT COUNT(*) FROM cost_categories')?->fetchColumn();
        $this->assertGreaterThan(10, $anzahl);
        $this->assertFileDoesNotExist(public_path('storage'));

        // Zweiter Lauf: nichts wird doppelt eingespielt.
        $zweiter = $this->artisanProzess(['smartabrechnen:install', '--no-interaction'], $datenbank);

        $this->assertSame(0, $zweiter->getExitCode(), $zweiter->getErrorOutput());
        $this->assertStringContainsString('kein Seed erforderlich', $zweiter->getOutput());
        $this->assertSame($anzahl, (int) $pdo->query('SELECT COUNT(*) FROM cost_categories')?->fetchColumn());

        // Die Anwendung antwortet mit den erzeugten Caches.
        $antwort = $this->anfrageProzess($datenbank);
        $this->assertSame(0, $antwort->getExitCode(), $antwort->getErrorOutput());
        $this->assertSame('200 config-cached routes-cached events-cached', trim($antwort->getOutput()));
    }

    public function test_install_bricht_ohne_app_key_mit_klarer_meldung_ab(): void
    {
        $datenbank = $this->arbeit.'/ohne-key.sqlite';
        touch($datenbank);

        $prozess = $this->artisanProzess(['smartabrechnen:install', '--no-interaction'], $datenbank, ['APP_KEY' => '']);

        $this->assertSame(1, $prozess->getExitCode());
        $ausgabe = $prozess->getOutput().$prozess->getErrorOutput();
        $this->assertStringContainsString('[FEHLER] APP_KEY', $ausgabe);
        $this->assertStringContainsString('key:generate', $ausgabe);
        $this->assertStringContainsString('abgebrochen', $ausgabe);

        // Es wurde nichts migriert.
        $pdo = new PDO('sqlite:'.$datenbank);
        $tabellen = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")?->fetchColumn();
        $this->assertSame(0, (int) $tabellen);
    }

    /**
     * @param  list<string>  $argumente
     * @param  array<string, string>  $zusatz
     */
    private function artisanProzess(array $argumente, string $datenbank, array $zusatz = []): Process
    {
        $prozess = new Process(
            array_merge([PHP_BINARY, 'artisan'], $argumente),
            base_path(),
            array_merge($this->umgebung($datenbank), $zusatz),
            null,
            180,
        );
        $prozess->run();

        return $prozess;
    }

    private function anfrageProzess(string $datenbank): Process
    {
        $skript = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $response = $kernel->handle(Illuminate\Http\Request::create('https://beispiel.test/', 'GET'));
            echo $response->getStatusCode();
            echo $app->configurationIsCached() ? ' config-cached' : ' config-live';
            echo $app->routesAreCached() ? ' routes-cached' : ' routes-live';
            echo $app->eventsAreCached() ? ' events-cached' : ' events-live';
            PHP;

        $prozess = new Process([PHP_BINARY, '-r', $skript], base_path(), $this->umgebung($datenbank), null, 120);
        $prozess->run();

        return $prozess;
    }

    /**
     * Umgebung des Unterprozesses. Die Werte ueberschreiben die .env des
     * Projekts, weil vorhandene Umgebungsvariablen Vorrang haben.
     *
     * @return array<string, string>
     */
    private function umgebung(string $datenbank): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://beispiel.test',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $datenbank,
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'FILESYSTEM_DISK' => 'local',
            'AI_PRIMARY_PROVIDER' => 'fake',
            'AI_FALLBACK_ENABLED' => 'false',
            'STRIPE_SECRET' => '',
            'TRUSTED_PROXIES' => '*',
            'APP_CONFIG_CACHE' => $this->arbeit.'/cache/config.php',
            'APP_ROUTES_CACHE' => $this->arbeit.'/cache/routes-v7.php',
            'APP_EVENTS_CACHE' => $this->arbeit.'/cache/events.php',
            'VIEW_COMPILED_PATH' => $this->arbeit.'/views',
            'PATH' => (string) getenv('PATH'),
        ];
    }
}
