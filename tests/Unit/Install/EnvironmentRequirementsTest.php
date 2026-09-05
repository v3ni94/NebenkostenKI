<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use App\Application\Install\EnvironmentRequirements;
use App\Application\Install\RequirementResult;
use PHPUnit\Framework\TestCase;

final class EnvironmentRequirementsTest extends TestCase
{
    private string $verzeichnis = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->verzeichnis = sys_get_temp_dir().'/install-req-'.bin2hex(random_bytes(6));
        mkdir($this->verzeichnis, 0750, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->verzeichnis)) {
            @chmod($this->verzeichnis, 0750);
            @rmdir($this->verzeichnis);
        }

        parent::tearDown();
    }

    public function test_erfuellte_umgebung_wird_erkannt(): void
    {
        $pruefung = new EnvironmentRequirements(
            [$this->verzeichnis],
            'base64:'.base64_encode(random_bytes(32)),
            '8.3.0',
            EnvironmentRequirements::REQUIRED_EXTENSIONS,
        );

        $this->assertTrue($pruefung->fulfilled());
        $this->assertSame([], $pruefung->missingExtensions());
    }

    public function test_zu_alte_php_version_wird_gemeldet(): void
    {
        $pruefung = new EnvironmentRequirements([$this->verzeichnis], 'base64:'.base64_encode(random_bytes(32)), '8.2.9', EnvironmentRequirements::REQUIRED_EXTENSIONS);

        $this->assertFalse($pruefung->fulfilled());
        $this->assertStringContainsString('PHP 8.2.9 ist zu alt', $this->meldung($pruefung, 'PHP-Version'));
    }

    public function test_fehlende_erweiterungen_werden_einzeln_genannt(): void
    {
        $geladen = array_values(array_diff(EnvironmentRequirements::REQUIRED_EXTENSIONS, ['intl', 'gd']));
        $pruefung = new EnvironmentRequirements([$this->verzeichnis], 'base64:'.base64_encode(random_bytes(32)), '8.3.0', $geladen);

        $this->assertSame(['gd', 'intl'], $pruefung->missingExtensions());
        $this->assertStringContainsString('"gd"', $this->meldung($pruefung, 'PHP-Erweiterung gd'));
        $this->assertStringContainsString('IONOS-Control-Center', $this->meldung($pruefung, 'PHP-Erweiterung intl'));
    }

    public function test_fehlendes_verzeichnis_wird_gemeldet(): void
    {
        $pruefung = new EnvironmentRequirements([$this->verzeichnis.'/gibt-es-nicht'], 'base64:'.base64_encode(random_bytes(32)), '8.3.0', EnvironmentRequirements::REQUIRED_EXTENSIONS);

        $this->assertFalse($pruefung->fulfilled());
    }

    public function test_fehlender_oder_kurzer_app_key_wird_gemeldet(): void
    {
        $ohne = new EnvironmentRequirements([$this->verzeichnis], null, '8.3.0', EnvironmentRequirements::REQUIRED_EXTENSIONS);
        $kurz = new EnvironmentRequirements([$this->verzeichnis], 'base64:'.base64_encode('kurz'), '8.3.0', EnvironmentRequirements::REQUIRED_EXTENSIONS);

        $this->assertStringContainsString('key:generate', $this->meldung($ohne, 'APP_KEY'));
        $this->assertStringContainsString('zu kurz', $this->meldung($kurz, 'APP_KEY'));
        $this->assertFalse($kurz->fulfilled());
    }

    private function meldung(EnvironmentRequirements $pruefung, string $name): string
    {
        foreach ($pruefung->check() as $ergebnis) {
            if ($ergebnis instanceof RequirementResult && $ergebnis->name === $name) {
                return $ergebnis->message;
            }
        }

        $this->fail('Kein Ergebnis mit Namen '.$name);
    }
}
