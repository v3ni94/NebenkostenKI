<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * Nachweise zum Deployskript bin/deploy-sftp.php.
 *
 * Das Skript ist eigenstaendig, beendet den Prozess selbst und braucht eine
 * echte SFTP-Verbindung. Ein Verbindungsfake ist ohne Umbau des Skripts nicht
 * moeglich. Geprueft wird deshalb der Quelltext auf die Regeln, deren
 * Verletzung zu den bekannten Fehlerbildern fuehrte:
 *
 *  - Rohe SFTP-Aufrufe (symlink, delete) loesen relative Pfade gegen das
 *    Anmeldeverzeichnis des SFTP-Nutzers auf, nicht gegen SFTP_DEPLOY_ROOT.
 *    Sie muessen absolute Pfade unterhalb des Roots erhalten.
 *  - Ein abgebrochener Upload darf kein halbes Release hinterlassen, das den
 *    naechsten Lauf blockiert oder als Rollbackziel gilt.
 */
final class DeploySftpScriptTest extends TestCase
{
    private static function skript(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/bin/deploy-sftp.php');
    }

    public function test_das_skript_ist_syntaktisch_gueltig(): void
    {
        $ausgabe = [];
        $code = 1;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg(dirname(__DIR__, 3).'/bin/deploy-sftp.php'), $ausgabe, $code);

        $this->assertSame(0, $code, implode("\n", $ausgabe));
    }

    public function test_rohe_sftp_aufrufe_verwenden_absolute_pfade_unterhalb_des_roots(): void
    {
        $skript = self::skript();

        preg_match_all('/\$this->connection\(\)->(symlink|delete)\(([^;]*)\);/', $skript, $treffer, PREG_SET_ORDER);

        $this->assertNotSame([], $treffer, 'Symlink-Aufrufe muessen vorhanden sein.');

        foreach ($treffer as $aufruf) {
            $argumente = $aufruf[2];
            // Beim symlink ist das erste Argument das relative Linkziel, das
            // zweite der anzulegende Linkpfad. Bei delete gibt es nur den Pfad.
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

    public function test_die_symlink_anlage_prueft_den_rueckgabewert(): void
    {
        $this->assertMatchesRegularExpression(
            '/if \(! \$this->connection\(\)->symlink\(\'\.\.\/\.\.\/shared\/\.env\'/',
            self::skript(),
            'Ein fehlgeschlagener Symlink muss den Lauf abbrechen und darf nicht still ignoriert werden.'
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
}
