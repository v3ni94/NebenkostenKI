<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use phpseclib3\Net\SFTP;
use PHPUnit\Framework\TestCase;
use SftpDeployer;

/**
 * Nachweise zum Deployskript bin/deploy-sftp.php.
 *
 * Das Skript ist eigenstaendig und braucht im Betrieb eine echte
 * SFTP-Verbindung. Fuer den Verhaltenstest werden Dateisystem und Verbindung
 * von aussen gesetzt: Das Dateisystem ist ein lokales Verzeichnis, das die
 * Rolle von SFTP_DEPLOY_ROOT uebernimmt, die Verbindung ein Fake, der die
 * rohen SFTP-Aufrufe (symlink, delete) aufzeichnet und als echte Symlinks im
 * selben Verzeichnis ausfuehrt. Damit laesst sich nach dem Umschalten pruefen,
 * ob die Verknuepfungen von current/ tatsaechlich auf shared/ zeigen.
 *
 * Ergaenzend bleiben Quelltextpruefungen fuer die Regeln, deren Verletzung zu
 * den bekannten Fehlerbildern fuehrte:
 *
 *  - Rohe SFTP-Aufrufe loesen relative Pfade gegen das Anmeldeverzeichnis des
 *    SFTP-Nutzers auf, nicht gegen SFTP_DEPLOY_ROOT. Sie muessen absolute
 *    Pfade unterhalb des Roots erhalten.
 *  - Ein abgebrochener Upload darf kein halbes Release hinterlassen, das den
 *    naechsten Lauf blockiert oder als Rollbackziel gilt.
 */
final class DeploySftpScriptTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $verzeichnisse = [];

    protected function tearDown(): void
    {
        foreach ($this->verzeichnisse as $verzeichnis) {
            self::entferne($verzeichnis);
        }

        parent::tearDown();
    }

    private static function skriptpfad(): string
    {
        return dirname(__DIR__, 3).'/bin/deploy-sftp.php';
    }

    private static function skript(): string
    {
        return (string) file_get_contents(self::skriptpfad());
    }

    public function test_das_skript_ist_syntaktisch_gueltig(): void
    {
        $ausgabe = [];
        $code = 1;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg(self::skriptpfad()), $ausgabe, $code);

        $this->assertSame(0, $code, implode("\n", $ausgabe));
    }

    // ----------------------------------------------------------------------
    // Verhaltenstests mit Fake der SFTP-Verbindung
    // ----------------------------------------------------------------------

    public function test_nach_dem_umschalten_zeigen_env_und_storage_von_current_auf_shared(): void
    {
        $root = $this->serverwurzel();
        $quelle = $this->paket('release-a');
        $fake = new FakeSftpVerbindung;

        $code = $this->deployer($root, $fake)->deploy($quelle);

        $this->assertSame(0, $code);
        $this->assertDirectoryExists($root.'/current');
        $this->assertDirectoryDoesNotExist($root.'/releases/release-a');

        // Die Symlink-Probe in shared/ ist nicht Gegenstand dieser Pruefung.
        $verknuepfungen = array_values(array_filter(
            $fake->symlinks,
            static fn (array $eintrag): bool => str_starts_with($eintrag['link'], $root.'/releases/'),
        ));

        // Linkort: unterhalb des Roots im Releaseverzeichnis, nicht im
        // Anmeldeverzeichnis des SFTP-Nutzers.
        $this->assertSame(
            [$root.'/releases/release-a/.env', $root.'/releases/release-a/storage'],
            array_column($verknuepfungen, 'link'),
        );

        // Linkziel: absolut unterhalb des Roots. Ein relatives Ziel wie
        // ../../shared/.env stimmt nur in releases/<name>/ und bricht nach dem
        // Umbenennen nach current/.
        $this->assertSame(
            [$root.'/shared/.env', $root.'/shared/storage'],
            array_column($verknuepfungen, 'target'),
        );

        $this->assertTrue(is_link($root.'/current/.env'));
        $this->assertSame(realpath($root.'/shared/.env'), realpath($root.'/current/.env'));
        $this->assertSame("APP_KEY=base64:testschluessel\n", file_get_contents($root.'/current/.env'));

        $this->assertTrue(is_link($root.'/current/storage'));
        $this->assertSame(realpath($root.'/shared/storage'), realpath($root.'/current/storage'));
        $this->assertFileExists($root.'/current/RELEASE');
        $this->assertSame('release-a', trim((string) file_get_contents($root.'/current/RELEASE')));
    }

    public function test_ein_geparktes_und_ein_zurueckgeholtes_release_behalten_gueltige_verknuepfungen(): void
    {
        $root = $this->serverwurzel();
        $fake = new FakeSftpVerbindung;

        $this->assertSame(0, $this->deployer($root, $fake)->deploy($this->paket('release-a')));
        $this->assertSame(0, $this->deployer($root, $fake)->deploy($this->paket('release-b')));

        $this->assertSame('release-b', trim((string) file_get_contents($root.'/current/RELEASE')));
        $this->assertSame(realpath($root.'/shared/.env'), realpath($root.'/current/.env'));

        // Das geparkte Release liegt wieder unter releases/ und bleibt
        // rollbackfaehig: seine Verknuepfungen zeigen weiterhin auf shared/.
        $this->assertDirectoryExists($root.'/releases/release-a');
        $this->assertSame(realpath($root.'/shared/.env'), realpath($root.'/releases/release-a/.env'));
        $this->assertSame(realpath($root.'/shared/storage'), realpath($root.'/releases/release-a/storage'));

        $this->assertSame(0, $this->deployer($root, $fake)->rollback('release-a'));

        $this->assertSame('release-a', trim((string) file_get_contents($root.'/current/RELEASE')));
        $this->assertSame(realpath($root.'/shared/.env'), realpath($root.'/current/.env'));
        $this->assertSame(realpath($root.'/shared/storage'), realpath($root.'/current/storage'));
        $this->assertSame(realpath($root.'/shared/.env'), realpath($root.'/releases/release-b/.env'));
    }

    public function test_ohne_shared_env_wird_nichts_uebertragen(): void
    {
        $root = $this->serverwurzel();
        unlink($root.'/shared/.env');
        $fake = new FakeSftpVerbindung;

        $code = $this->deployer($root, $fake)->deploy($this->paket('release-a'));

        $this->assertSame(1, $code);
        $this->assertDirectoryDoesNotExist($root.'/releases/release-a');
        $this->assertDirectoryDoesNotExist($root.'/current');
        $this->assertSame([], array_filter(
            $fake->symlinks,
            static fn (array $eintrag): bool => str_starts_with($eintrag['link'], $root.'/releases/'),
        ));
    }

    // ----------------------------------------------------------------------
    // Quelltextpruefungen
    // ----------------------------------------------------------------------

    public function test_rohe_sftp_aufrufe_verwenden_absolute_pfade_unterhalb_des_roots(): void
    {
        $skript = self::skript();

        preg_match_all('/\$this->connection\(\)->(symlink|delete)\(([^;]*)\);/', $skript, $treffer, PREG_SET_ORDER);

        $this->assertNotSame([], $treffer, 'Symlink-Aufrufe muessen vorhanden sein.');

        foreach ($treffer as $aufruf) {
            $argumente = $aufruf[2];
            // Beim symlink ist das erste Argument das Linkziel, das zweite der
            // anzulegende Linkpfad. Bei delete gibt es nur den Pfad.
            $pfad = $aufruf[1] === 'symlink' ? (string) preg_replace('/^[^,]*,\s*/', '', $argumente) : $argumente;

            $this->assertStringStartsWith(
                '$this->absolutePath(',
                trim($pfad),
                'Der Pfad in '.$aufruf[0].' muss mit SFTP_DEPLOY_ROOT absolut gemacht werden.'
            );
        }

        $this->assertMatchesRegularExpression(
            '/function absolutePath\(string \$path\): string\s*\{.*SFTP_DEPLOY_ROOT.*\}/s',
            $skript
        );
    }

    public function test_die_symlink_anlage_prueft_den_rueckgabewert_und_verwendet_absolute_ziele(): void
    {
        $skript = self::skript();

        $this->assertMatchesRegularExpression(
            '/if \(! \$this->connection\(\)->symlink\(\$this->absolutePath\(\'shared\/\.env\'\), \$this->absolutePath\(\$target\.\'\/\.env\'\)\)/',
            $skript,
            'Ein fehlgeschlagener Symlink muss den Lauf abbrechen, und das Linkziel muss absolut unterhalb des Roots liegen.'
        );
        $this->assertStringNotContainsString(
            "'../../shared/",
            $skript,
            'Relative Linkziele brechen nach dem Umbenennen von releases/<name> nach current.'
        );
    }

    public function test_ein_abgebrochener_upload_entfernt_das_halbe_release(): void
    {
        $skript = self::skript();

        $this->assertMatchesRegularExpression(
            '/try \{\s*\$failure = \$this->upload\(.*?\} catch \(Throwable \$exception\) \{\s*\$this->abandon\(\$target\);\s*throw \$exception;/s',
            $skript,
            'Eine Ausnahme waehrend des Uploads muss das Releaseverzeichnis entfernen.'
        );
        $this->assertMatchesRegularExpression(
            '/if \(\$failure !== null\) \{\s*\$this->abandon\(\$target\);\s*return \$this->fail\(\$failure\);/s',
            $skript,
            'Ein gemeldeter Abbruch des Uploads muss das Releaseverzeichnis entfernen.'
        );
        $this->assertMatchesRegularExpression(
            '/function abandon\(string \$target\): void\s*\{.*deleteDirectory\(\$target\)/s',
            $skript
        );
    }

    public function test_rollback_prueft_die_integritaet_und_schaltet_bei_fehlgeschlagenem_smoke_test_zurueck(): void
    {
        $skript = self::skript();

        preg_match('/public function rollback\(string \$release\): int\s*\{(.*?)\n    \}/s', $skript, $treffer);

        $this->assertArrayHasKey(1, $treffer, 'rollback() muss vorhanden sein.');
        $rollback = $treffer[1];

        $this->assertStringContainsString('$this->integrityFailure(\'releases/\'.$release)', $rollback);
        $this->assertLessThan(
            strpos($rollback, '$this->switchTo($release)'),
            strpos($rollback, '$this->integrityFailure('),
            'Die Integritaetspruefung muss vor dem Umschalten laufen.'
        );
        $this->assertMatchesRegularExpression(
            '/if \(\$previous !== null\) \{\s*\$this->switchTo\(\$previous\);/s',
            $rollback,
            'Nach fehlgeschlagenem Smoke-Test muss auf das zuvor aktive Release zurueckgeschaltet werden.'
        );
    }

    public function test_die_integritaetspruefung_verlangt_pflichtdateien_marker_und_env(): void
    {
        preg_match('/private function integrityFailure\(string \$directory\): \?string\s*\{(.*?)\n    \}/s', self::skript(), $treffer);

        $this->assertArrayHasKey(1, $treffer);
        $this->assertStringContainsString('ReleasePackage::REQUIRED_FILES', $treffer[1]);
        $this->assertStringContainsString('self::RELEASE_MARKER', $treffer[1]);
        $this->assertStringContainsString("'/.env'", $treffer[1]);
    }

    // ----------------------------------------------------------------------
    // Hilfen
    // ----------------------------------------------------------------------

    private function deployer(string $root, FakeSftpVerbindung $fake): SftpDeployer
    {
        require_once self::skriptpfad();

        $ausgabe = fopen('php://memory', 'w+');
        $this->assertIsResource($ausgabe);

        $dateisystem = new Filesystem(
            new LocalFilesystemAdapter($root, null, LOCK_EX, LocalFilesystemAdapter::SKIP_LINKS),
        );

        return new SftpDeployer(
            ['SFTP_DEPLOY_ROOT' => $root, 'SFTP_HOST' => 'fake.invalid', 'SFTP_USERNAME' => 'fake', 'SFTP_PASSWORD' => 'fake'],
            false,
            3,
            null,
            $dateisystem,
            $fake,
            $ausgabe,
        );
    }

    /**
     * Serverwurzel mit shared/.env, wie sie der Betreiber einmalig anlegt.
     */
    private function serverwurzel(): string
    {
        $root = $this->temporaeresVerzeichnis('deploy-root');

        mkdir($root.'/shared', 0755, true);
        file_put_contents($root.'/shared/.env', "APP_KEY=base64:testschluessel\n");

        return $root;
    }

    /**
     * Minimales Releasepaket mit allen Pflichtdateien.
     */
    private function paket(string $release): string
    {
        $quelle = $this->temporaeresVerzeichnis('deploy-paket');

        foreach ([
            'artisan' => "<?php\n",
            'public/index.php' => "<?php\n",
            'public/.htaccess' => "RewriteEngine On\n",
            'vendor/autoload.php' => "<?php\n",
            'public/build/manifest.json' => "{}\n",
            'bootstrap/app.php' => "<?php\n",
            'storage/logs/.gitignore' => "*\n",
            'public/version.json' => json_encode(['release' => $release], JSON_THROW_ON_ERROR)."\n",
        ] as $pfad => $inhalt) {
            $verzeichnis = dirname($quelle.'/'.$pfad);

            if (! is_dir($verzeichnis)) {
                mkdir($verzeichnis, 0755, true);
            }

            file_put_contents($quelle.'/'.$pfad, $inhalt);
        }

        return $quelle;
    }

    private function temporaeresVerzeichnis(string $praefix): string
    {
        $pfad = sys_get_temp_dir().'/'.$praefix.'-'.bin2hex(random_bytes(6));
        mkdir($pfad, 0755, true);

        $echt = realpath($pfad);
        $this->assertIsString($echt);

        $this->verzeichnisse[] = $echt;

        return $echt;
    }

    private static function entferne(string $pfad): void
    {
        if (is_link($pfad) || is_file($pfad)) {
            @unlink($pfad);

            return;
        }

        if (! is_dir($pfad)) {
            return;
        }

        $eintraege = scandir($pfad);

        foreach ($eintraege === false ? [] : $eintraege as $eintrag) {
            if ($eintrag !== '.' && $eintrag !== '..') {
                self::entferne($pfad.'/'.$eintrag);
            }
        }

        @rmdir($pfad);
    }
}

/**
 * Fake der rohen SFTP-Verbindung. Er verbindet sich nirgendwohin, zeichnet
 * symlink- und delete-Aufrufe auf und fuehrt sie auf dem lokalen Dateisystem
 * aus, in dem SFTP_DEPLOY_ROOT im Test liegt. Die Pfade kommen unveraendert
 * an, genau wie sie ein echter SFTP-Server erhielte.
 */
final class FakeSftpVerbindung extends SFTP
{
    /**
     * @var list<array{target: string, link: string}>
     */
    public array $symlinks = [];

    /**
     * @var list<string>
     */
    public array $geloescht = [];

    public function __construct()
    {
        parent::__construct('fake.invalid');
    }

    /**
     * @param  string  $target
     * @param  string  $link
     */
    public function symlink($target, $link): bool
    {
        $this->symlinks[] = ['target' => $target, 'link' => $link];

        return symlink($target, $link);
    }

    /**
     * @param  string  $path
     * @param  bool  $recursive
     */
    public function delete($path, $recursive = true): bool
    {
        $this->geloescht[] = $path;

        return @unlink($path);
    }
}
