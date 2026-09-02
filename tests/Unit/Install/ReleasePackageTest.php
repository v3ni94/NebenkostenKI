<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use App\Application\Install\ReleasePackage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Was darf ins Releasepaket, was nicht (Masterprompt 21.1).
 */
final class ReleasePackageTest extends TestCase
{
    private string $quelle = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->quelle = sys_get_temp_dir().'/release-package-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->quelle)) {
            $this->loesche($this->quelle);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function pfade(): array
    {
        return [
            'Anwendungscode' => ['app/Models/User.php', true],
            'vendor' => ['vendor/autoload.php', true],
            'gebaute Assets' => ['public/build/manifest.json', true],
            'Front Controller' => ['public/index.php', true],
            'public htaccess' => ['public/.htaccess', true],
            'Wurzel htaccess' => ['.htaccess', true],
            'artisan' => ['artisan', true],
            'composer.json' => ['composer.json', true],
            'Speichergeruest' => ['storage/app/.gitignore', true],
            'Deployskript' => ['bin/deploy-sftp.php', true],
            '.env' => ['.env', false],
            '.env.production' => ['.env.production', false],
            '.env.example' => ['.env.example', false],
            'Tests' => ['tests/Feature/Install/InstallCommandTest.php', false],
            'node_modules' => ['node_modules/vite/index.js', false],
            'temporaere Uploads' => ['storage/app/temporary-uploads/abc.bin', false],
            'Logs' => ['storage/logs/laravel.log', false],
            'Logdatei anderswo' => ['app/debug.log', false],
            'SQLite' => ['database/database.sqlite', false],
            'Repository' => ['.git/HEAD', false],
            'Workflows' => ['.github/workflows/deploy.yml', false],
            'Frontendquellen' => ['resources/js/app.js', false],
            'Blade-Views bleiben' => ['resources/views/site/home.blade.php', true],
            'phpunit.xml' => ['phpunit.xml', false],
            'Dokumentation' => ['docs/betrieb/installation.md', false],
            'Composer auth' => ['auth.json', false],
            'bootstrap/cache' => ['bootstrap/cache/config.php', false],
        ];
    }

    #[DataProvider('pfade')]
    public function test_paketregeln(string $pfad, bool $erlaubt): void
    {
        $this->assertSame($erlaubt, (new ReleasePackage)->allows($pfad), $pfad);
    }

    public function test_verzeichnisse_werden_als_ganzes_ausgeschlossen(): void
    {
        $paket = new ReleasePackage;

        $this->assertFalse($paket->allows('tests', true));
        $this->assertFalse($paket->allows('node_modules', true));
        $this->assertFalse($paket->allows('storage/app/temporary-uploads', true));
        $this->assertTrue($paket->allows('storage/app', true));
        $this->assertTrue($paket->allows('app', true));
    }

    public function test_dateiliste_einer_quelle_enthaelt_nur_erlaubtes(): void
    {
        $this->lege([
            'artisan' => '#!/usr/bin/env php',
            'public/index.php' => '<?php',
            'public/build/manifest.json' => '{}',
            'vendor/autoload.php' => '<?php',
            'app/Models/User.php' => '<?php',
            '.env' => 'APP_KEY=geheim',
            '.env.staging' => 'APP_KEY=geheim',
            'tests/Unit/BeispielTest.php' => '<?php',
            'node_modules/x/index.js' => '',
            'storage/app/temporary-uploads/upload.bin' => 'bin',
            'storage/app/.gitignore' => '*',
            'storage/logs/laravel.log' => 'log',
            'storage/logs/.gitignore' => '*',
        ]);

        $dateien = (new ReleasePackage)->files($this->quelle);

        $this->assertSame([
            'app/Models/User.php',
            'artisan',
            'public/build/manifest.json',
            'public/index.php',
            'storage/app/.gitignore',
            'vendor/autoload.php',
        ], $dateien);

        $inhalt = implode("\n", $dateien);
        $this->assertStringNotContainsString('.env', $inhalt);
        $this->assertStringNotContainsString('tests/', $inhalt);
        $this->assertStringNotContainsString('temporary-uploads', $inhalt);
        $this->assertStringNotContainsString('node_modules', $inhalt);
    }

    public function test_pflichtdateien_werden_geprueft(): void
    {
        $this->lege(['artisan' => '']);

        $fehlend = (new ReleasePackage)->missingRequiredFiles($this->quelle);

        $this->assertContains('public/index.php', $fehlend);
        $this->assertContains('vendor/autoload.php', $fehlend);
        $this->assertContains('public/build/manifest.json', $fehlend);
        $this->assertNotContains('artisan', $fehlend);
    }

    public function test_workflow_verwendet_dieselben_ausschluesse(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/deploy.yml');

        foreach (['.env', 'tests', 'node_modules', 'storage/app/temporary-uploads', 'storage/logs', '.git', '.github'] as $muster) {
            $this->assertStringContainsString("--exclude '".$muster, $workflow, 'deploy.yml schliesst '.$muster.' nicht aus.');
        }

        // Es gibt keine frei erreichbare Migrationsdatei (Masterprompt 21.2).
        $this->assertFileDoesNotExist(dirname(__DIR__, 3).'/public/migrate.php');
        $this->assertFileDoesNotExist(dirname(__DIR__, 3).'/migrate.php');
    }

    /**
     * @param  array<string, string>  $dateien
     */
    private function lege(array $dateien): void
    {
        foreach ($dateien as $pfad => $inhalt) {
            $voll = $this->quelle.'/'.$pfad;
            @mkdir(dirname($voll), 0750, true);
            file_put_contents($voll, $inhalt);
        }
    }

    private function loesche(string $pfad): void
    {
        foreach (scandir($pfad) ?: [] as $eintrag) {
            if ($eintrag === '.' || $eintrag === '..') {
                continue;
            }

            $voll = $pfad.'/'.$eintrag;
            is_dir($voll) ? $this->loesche($voll) : unlink($voll);
        }

        rmdir($pfad);
    }
}
