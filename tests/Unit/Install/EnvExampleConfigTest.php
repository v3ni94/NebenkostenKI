<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Die ausgelieferte Vorlage .env.example muss zu einer betriebsfaehigen
 * Konfiguration fuehren.
 *
 * Laravel liefert fuer eine vorhandene, aber leere Zeile (SCHLUESSEL=) die
 * leere Zeichenkette und nicht null. Die dreiwertigen Schalter in config/ai.php
 * muessen leer wie "nicht gesetzt" behandeln, sonst schaltet die Vorlage die
 * KI-Anbindung ab (filter_var('') ist false) und erzeugt ein Tageslimit von
 * 0 Cent ((int) '' ist 0).
 *
 * Der Nachweis laeuft in einem Kindprozess ohne Testumgebung, weil phpunit.xml
 * einige dieser Variablen fuer den Testlauf ueberschreibt und das unveraenderliche
 * Env-Repository von Laravel sie im laufenden Prozess nicht ersetzen laesst.
 */
final class EnvExampleConfigTest extends TestCase
{
    private static function projektverzeichnis(): string
    {
        return dirname(__DIR__, 3);
    }

    public function test_die_vorlage_bindet_die_pipeline_und_setzt_kein_tageslimit(): void
    {
        $konfiguration = $this->aiKonfigurationAus('.env.example');

        self::assertNull($konfiguration['bind_document_pipeline'], 'Leeres AI_BIND_DOCUMENT_PIPELINE muss wie nicht gesetzt gelten (produktiv gebunden).');
        self::assertNull($konfiguration['max_daily_cost_cent_per_user'], 'Leeres AI_MAX_DAILY_COST_CENT_PER_USER muss "kein Limit" bedeuten, nicht 0 Cent.');
    }

    public function test_ausdrueckliche_werte_bleiben_wirksam(): void
    {
        $datei = '.env.'.bin2hex(random_bytes(4)).'.pruefung';
        $pfad = self::projektverzeichnis().'/'.$datei;

        file_put_contents($pfad, "AI_BIND_DOCUMENT_PIPELINE=false\nAI_MAX_DAILY_COST_CENT_PER_USER= 250 \n");

        try {
            $konfiguration = $this->aiKonfigurationAus($datei);
        } finally {
            @unlink($pfad);
        }

        self::assertFalse($konfiguration['bind_document_pipeline']);
        self::assertSame(250, $konfiguration['max_daily_cost_cent_per_user']);
    }

    public function test_die_vorlage_fuehrt_keine_variablen_ohne_wirkung(): void
    {
        $inhalt = (string) file_get_contents(self::projektverzeichnis().'/.env.example');

        // Von keinem env()-Aufruf gelesen, kein SDK eingebunden.
        self::assertStringNotContainsString('SENTRY_', $inhalt);
        // Wird nirgends ausgewertet; die Disk wird ueber FILESYSTEM_DISK gewaehlt.
        self::assertStringNotContainsString('S3_ENABLED', $inhalt);
        // Laravel 12 liest MAIL_SCHEME, nicht MAIL_ENCRYPTION.
        self::assertDoesNotMatchRegularExpression('/^MAIL_ENCRYPTION=/m', $inhalt);
        self::assertMatchesRegularExpression('/^MAIL_SCHEME=smtps$/m', $inhalt);
        self::assertMatchesRegularExpression('/^MAIL_PORT=465$/m', $inhalt);
    }

    /**
     * Wertet config/ai.php genau so aus, wie es Laravel beim Bootstrap tut:
     * Dotenv laedt die Datei in das Env-Repository, env() liest daraus.
     *
     * @return array{bind_document_pipeline: bool|null, max_daily_cost_cent_per_user: int|null}
     */
    private function aiKonfigurationAus(string $envDatei): array
    {
        $skript = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            Dotenv\Dotenv::create(Illuminate\Support\Env::getRepository(), $argv[1], $argv[2])->load();
            $config = require $argv[1].'/config/ai.php';
            echo json_encode([
                'bind_document_pipeline' => $config['bind_document_pipeline'],
                'max_daily_cost_cent_per_user' => $config['max_daily_cost_cent_per_user'],
            ]);
            PHP;

        // Die Variablen aus phpunit.xml duerfen den Kindprozess nicht erreichen,
        // sonst wuerde nicht die Vorlage, sondern die Testumgebung geprueft.
        $prozess = new Process([PHP_BINARY, '-r', $skript, self::projektverzeichnis(), $envDatei], self::projektverzeichnis(), [
            'PATH' => (string) getenv('PATH'),
            'AI_BIND_DOCUMENT_PIPELINE' => false,
            'AI_MAX_DAILY_COST_CENT_PER_USER' => false,
            'AI_PRIMARY_PROVIDER' => false,
            'AI_FALLBACK_ENABLED' => false,
            'AI_DATA_RETENTION_APPROVED' => false,
        ]);
        $prozess->setTimeout(60);
        $prozess->mustRun();

        /** @var array{bind_document_pipeline: bool|null, max_daily_cost_cent_per_user: int|null} $decoded */
        $decoded = json_decode($prozess->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
