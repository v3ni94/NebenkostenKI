<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Die vier Produktionscaches muessen fehlerfrei durchlaufen, und die Anwendung
 * muss danach noch antworten (Masterprompt 3.1: ohne Caches liest IONOS bei
 * jeder Anfrage die gesamte Konfiguration neu).
 *
 * route:cache scheitert an Closures in Routendateien. Die Routen bestehen
 * deshalb ausschliesslich aus Controllern, Route::view und Route::redirect;
 * dieser Test haelt das fest.
 */
final class ProductionCacheTest extends TestCase
{
    private string $arbeit = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->arbeit = storage_path('framework/testing/cache-'.Str::lower(Str::random(10)));
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

    public function test_alle_produktionscaches_laufen_durch_und_die_anwendung_antwortet(): void
    {
        foreach (['config:cache', 'route:cache', 'view:cache', 'event:cache'] as $befehl) {
            $prozess = $this->prozess([PHP_BINARY, 'artisan', $befehl, '--no-interaction']);

            $this->assertSame(0, $prozess->getExitCode(), $befehl.': '.$prozess->getOutput().$prozess->getErrorOutput());
        }

        $this->assertFileExists($this->arbeit.'/cache/config.php');
        $this->assertFileExists($this->arbeit.'/cache/routes-v7.php');
        $this->assertFileExists($this->arbeit.'/cache/events.php');

        $skript = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $codes = [];
            foreach (['/', '/preise', '/anmelden', '/impressum', '/up'] as $pfad) {
                $codes[] = $kernel->handle(Illuminate\Http\Request::create('https://beispiel.test'.$pfad, 'GET'))->getStatusCode();
            }
            echo implode(' ', $codes);
            echo $app->configurationIsCached() && $app->routesAreCached() && $app->eventsAreCached() ? ' cached' : ' live';
            PHP;

        $antwort = $this->prozess([PHP_BINARY, '-r', $skript]);

        $this->assertSame(0, $antwort->getExitCode(), $antwort->getErrorOutput());
        $this->assertSame('200 200 200 200 200 cached', trim($antwort->getOutput()));
    }

    public function test_routendateien_enthalten_keine_closure_routen(): void
    {
        foreach (File::files(base_path('routes')) as $datei) {
            if ($datei->getFilename() === 'console.php') {
                continue;
            }

            $inhalt = (string) file_get_contents($datei->getPathname());

            // Erlaubt sind Gruppen-Closures (Route::group, ->group(function)).
            // Nicht erlaubt sind Closures als Routenziel.
            $this->assertDoesNotMatchRegularExpression(
                '/Route::(get|post|put|patch|delete|any|match)\([^;]*?,\s*(static\s+)?(function|fn)\s*\(/s',
                $inhalt,
                $datei->getFilename().' enthaelt eine Closure-Route; route:cache wuerde scheitern.',
            );
        }
    }

    /**
     * @param  list<string>  $kommando
     */
    private function prozess(array $kommando): Process
    {
        $prozess = new Process($kommando, base_path(), [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://beispiel.test',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'FILESYSTEM_DISK' => 'local',
            'AI_PRIMARY_PROVIDER' => 'fake',
            'AI_FALLBACK_ENABLED' => 'false',
            'STRIPE_SECRET' => '',
            'APP_CONFIG_CACHE' => $this->arbeit.'/cache/config.php',
            'APP_ROUTES_CACHE' => $this->arbeit.'/cache/routes-v7.php',
            'APP_EVENTS_CACHE' => $this->arbeit.'/cache/events.php',
            'VIEW_COMPILED_PATH' => $this->arbeit.'/views',
            'PATH' => (string) getenv('PATH'),
        ], null, 180);
        $prozess->run();

        return $prozess;
    }
}
