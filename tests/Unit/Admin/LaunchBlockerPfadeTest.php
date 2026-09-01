<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Application\Admin\LaunchBlockerCheck;
use App\Application\Payment\OperatorInvoiceBlocker;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dateibasierte Livegang-Blocker: Rechtstexte und CI-Assets.
 *
 * Die Pruefung liest die tatsaechlich vorhandenen Dateien. Der Test legt
 * deshalb ein eigenes Verzeichnis an und weist nach, dass der Blocker
 * verschwindet, sobald die Voraussetzung erfuellt ist.
 */
final class LaunchBlockerPfadeTest extends TestCase
{
    private string $rechtstexte = '';

    private string $ci = '';

    protected function setUp(): void
    {
        parent::setUp();

        $basis = storage_path('framework/testing/blocker-'.Str::random(12));
        $this->rechtstexte = $basis.'/legal';
        $this->ci = $basis.'/ci';

        File::ensureDirectoryExists($this->rechtstexte);
        File::ensureDirectoryExists($this->ci);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->rechtstexte));

        parent::tearDown();
    }

    private function pruefung(): LaunchBlockerCheck
    {
        return new LaunchBlockerCheck(
            new OperatorInvoiceBlocker,
            $this->rechtstexte,
            $this->ci,
        );
    }

    public function test_fehlende_rechtstextseiten_sind_ein_blocker(): void
    {
        self::assertTrue($this->pruefung()->report()->has(LaunchBlockerCheck::RECHTSTEXTE));
    }

    public function test_freigegebene_rechtstexte_loesen_den_blocker_auf(): void
    {
        foreach (['impressum', 'datenschutz', 'agb', 'widerruf'] as $seite) {
            File::put(
                $this->rechtstexte.'/'.$seite.'.blade.php',
                '<p>Freigegebene Textfassung der Seite '.$seite.'.</p>',
            );
        }

        self::assertFalse($this->pruefung()->report()->has(LaunchBlockerCheck::RECHTSTEXTE));
    }

    public function test_eine_einzelne_platzhalterseite_haelt_den_blocker_offen(): void
    {
        foreach (['impressum', 'datenschutz', 'agb'] as $seite) {
            File::put($this->rechtstexte.'/'.$seite.'.blade.php', '<p>Freigegebene Textfassung.</p>');
        }

        File::put($this->rechtstexte.'/widerruf.blade.php', '<p>Platzhalterfassung, noch nicht freigegeben.</p>');

        self::assertTrue($this->pruefung()->report()->has(LaunchBlockerCheck::RECHTSTEXTE));
    }

    public function test_fehlende_ci_assets_sind_ein_blocker_und_verschwinden_mit_dem_logo(): void
    {
        self::assertTrue($this->pruefung()->report()->has(LaunchBlockerCheck::CI_ASSETS));

        File::put($this->ci.'/Logo_HVM.jpg', 'nur ein Platzhalterinhalt für den Test');

        self::assertFalse($this->pruefung()->report()->has(LaunchBlockerCheck::CI_ASSETS));
    }
}
