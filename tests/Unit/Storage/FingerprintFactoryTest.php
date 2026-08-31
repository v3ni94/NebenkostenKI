<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\FingerprintFactory;
use Tests\TestCase;

/**
 * Prueft den schluesselgebundenen Fingerabdruck.
 *
 * VERBINDLICH (ARCHITECTURE.md 5.2): Dauerhaft gespeichert wird ausschliesslich
 * ein HMAC-SHA-256 mit einem aus APP_KEY abgeleiteten Schluessel. Der reine
 * SHA-256 des Inhalts waere ein Wiedererkennungsmerkmal der geloeschten
 * Originaldatei und darf nirgends erscheinen.
 */
class FingerprintFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    public function test_gleicher_inhalt_ergibt_gleichen_fingerabdruck(): void
    {
        $factory = new FingerprintFactory;

        $this->assertSame(
            $factory->forContents(SampleFiles::pdf()),
            $factory->forContents(SampleFiles::pdf())
        );
    }

    public function test_unterschiedlicher_inhalt_ergibt_unterschiedlichen_fingerabdruck(): void
    {
        $factory = new FingerprintFactory;

        $this->assertNotSame(
            $factory->forContents(SampleFiles::pdf(1)),
            $factory->forContents(SampleFiles::pdf(2))
        );
    }

    public function test_datei_und_inhalt_ergeben_denselben_fingerabdruck(): void
    {
        $factory = new FingerprintFactory;
        $inhalt = SampleFiles::pdf(2);

        $this->assertSame(
            $factory->forContents($inhalt),
            $factory->forFile(SampleFiles::write($inhalt, 'pdf'))
        );
    }

    public function test_fingerabdruck_ist_niemals_der_reine_sha256(): void
    {
        $factory = new FingerprintFactory;
        $inhalt = SampleFiles::pdf();

        $hmac = $factory->forContents($inhalt);

        $this->assertSame(64, strlen($hmac));
        $this->assertNotSame(hash('sha256', $inhalt), $hmac);
        $this->assertNotSame(hash('sha256', hash('sha256', $inhalt)), $hmac);
    }

    public function test_anderer_anwendungsschluessel_ergibt_anderen_fingerabdruck(): void
    {
        $factory = new FingerprintFactory;
        $inhalt = SampleFiles::pdf();

        $mitSchluesselA = $factory->forContents($inhalt);

        config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);

        $this->assertNotSame($mitSchluesselA, $factory->forContents($inhalt));
    }
}
