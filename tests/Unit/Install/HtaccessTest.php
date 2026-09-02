<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Nachweis der Sperrregeln des Document-Root-Rueckfalls (Wurzel-.htaccess).
 *
 * Apache steht im Testlauf nicht zur Verfuegung. Der Test wertet deshalb die
 * RewriteRule-Sperren der Datei mit derselben Regex-Semantik aus, die
 * mod_rewrite auf den Pfad relativ zum Verzeichnis der .htaccess anwendet.
 * Zusaetzlich prueft bin/deploy-sftp.php nach jedem Umschalten per HTTP, dass
 * /.env und /storage nicht ausgeliefert werden.
 */
final class HtaccessTest extends TestCase
{
    private static function wurzel(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/.htaccess');
    }

    /**
     * Sperrmuster (RewriteRule ... - [F,L]) in Dateireihenfolge.
     *
     * @return list<string>
     */
    private static function sperren(): array
    {
        preg_match_all('/^\s*RewriteRule\s+(\S+)\s+-\s+\[F,L\]/m', self::wurzel(), $treffer);

        return $treffer[1];
    }

    private static function gesperrt(string $pfad): bool
    {
        foreach (self::sperren() as $muster) {
            if (preg_match('#'.$muster.'#', $pfad) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function gesperrtePfade(): array
    {
        return [
            '.env' => ['.env'],
            '.env.backup' => ['.env.backup'],
            'shared .env' => ['shared/.env'],
            'current .env' => ['current/.env'],
            'release .env' => ['releases/release-1/.env'],
            'Logs' => ['storage/logs/laravel.log'],
            'Kurzzeitbereich' => ['storage/app/temporary-uploads/datei.pdf'],
            'storage im Release' => ['current/storage/logs/laravel.log'],
            'vendor' => ['vendor/autoload.php'],
            'app' => ['app/Models/User.php'],
            'config' => ['config/database.php'],
            'bootstrap cache' => ['bootstrap/cache/config.php'],
            'Repository' => ['.git/config'],
            'composer.json' => ['composer.json'],
            'artisan' => ['artisan'],
            'Architekturdatei' => ['ARCHITECTURE.md'],
        ];
    }

    #[DataProvider('gesperrtePfade')]
    public function test_pfad_ist_gesperrt(string $pfad): void
    {
        $this->assertTrue(self::gesperrt($pfad), $pfad.' muss gesperrt sein.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function freiePfade(): array
    {
        return [
            'Startseite' => [''],
            'Seite' => ['preise'],
            'Front Controller' => ['current/public/index.php'],
            'Assets' => ['current/public/build/assets/app.css'],
            'einfaches Layout' => ['public/index.php'],
            'ACME' => ['.well-known/acme-challenge/token'],
            'Logo' => ['current/public/ci/Logo_HVM.svg'],
        ];
    }

    #[DataProvider('freiePfade')]
    public function test_pfad_bleibt_frei(string $pfad): void
    {
        $this->assertFalse(self::gesperrt($pfad), $pfad.' darf nicht gesperrt sein.');
    }

    public function test_sperren_stehen_vor_der_umschreibung(): void
    {
        $inhalt = self::wurzel();
        $ersteSperre = strpos($inhalt, '[F,L]');
        $umschreibung = strpos($inhalt, 'current/public/$1');

        $this->assertNotFalse($ersteSperre);
        $this->assertNotFalse($umschreibung);
        $this->assertLessThan($umschreibung, $ersteSperre, 'Sperren muessen vor der Umschreibung nach public/ stehen.');
        $this->assertStringContainsString('Options -Indexes', $inhalt);
        $this->assertStringContainsString('Require all denied', $inhalt);
    }

    public function test_public_htaccess_leitet_www_dauerhaft_um(): void
    {
        $inhalt = (string) file_get_contents(dirname(__DIR__, 3).'/public/.htaccess');

        $this->assertStringContainsString('RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]', $inhalt);
        $this->assertStringContainsString('RewriteRule ^ https://%1%{REQUEST_URI} [R=301,L]', $inhalt);
        $this->assertStringNotContainsString('smart-abrechnen.de', $inhalt, 'Die Domain darf nicht hart codiert sein.');
    }
}
