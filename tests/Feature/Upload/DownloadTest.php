<?php

declare(strict_types=1);

namespace Tests\Feature\Upload;

use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Models\GeneratedDocument;
use App\Services\Storage\ArtifactStorage;
use App\Services\Storage\ArtifactType;
use App\Services\Storage\SignedDownloadUrlFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Prueft die Auslieferung erzeugter Ergebnisartefakte (Abschnitt 3.4 und 19).
 *
 * Der Zugriff laeuft ausschliesslich ueber eine autorisierte Streaming-Route
 * oder einen kurzlebigen signierten Link. Es gibt keinen oeffentlichen Pfad und
 * keinen Abruf einer Originaldatei.
 */
class DownloadTest extends TestCase
{
    use BuildsUploadWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpUploadWorld();

        config(['smartabrechnen.retention.signed_download_ttl_minutes' => 30]);
    }

    public function test_eigener_nutzer_erhaelt_das_artefakt(): void
    {
        $artefakt = $this->erzeugeArtefakt();

        $antwort = $this->actingAs($this->welt()['user'])
            ->get('/app/downloads/'.$artefakt->getKey());

        $antwort->assertOk();
        $antwort->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            'mieterabrechnung-final.pdf',
            (string) $antwort->headers->get('content-disposition')
        );
    }

    public function test_fremder_nutzer_erhaelt_404(): void
    {
        $artefakt = $this->erzeugeArtefakt();

        $fremd = $this->makeWorld();

        $this->actingAs($fremd['user'])
            ->get('/app/downloads/'.$artefakt->getKey())
            ->assertNotFound();
    }

    public function test_signierter_link_funktioniert_innerhalb_der_gueltigkeit(): void
    {
        $artefakt = $this->erzeugeArtefakt();

        $url = (new SignedDownloadUrlFactory)->forRoute(
            'portal.downloads.signed',
            ['generatedDocument' => (string) $artefakt->getKey()]
        );

        $this->actingAs($this->welt()['user'])->get($url)->assertOk();
    }

    public function test_abgelaufener_signierter_link_wird_abgewiesen(): void
    {
        $artefakt = $this->erzeugeArtefakt();

        $url = (new SignedDownloadUrlFactory)->expiredForRoute(
            'portal.downloads.signed',
            ['generatedDocument' => (string) $artefakt->getKey()]
        );

        $this->actingAs($this->welt()['user'])->get($url)->assertForbidden();
    }

    public function test_signierter_link_laeuft_nach_der_konfigurierten_frist_ab(): void
    {
        $artefakt = $this->erzeugeArtefakt();

        config(['smartabrechnen.retention.signed_download_ttl_minutes' => 5]);

        $factory = new SignedDownloadUrlFactory;

        $this->assertSame(5, $factory->ttlMinutes());

        $url = $factory->forRoute(
            'portal.downloads.signed',
            ['generatedDocument' => (string) $artefakt->getKey()]
        );

        Carbon::setTestNow(Carbon::now()->addMinutes(6));

        $this->actingAs($this->welt()['user'])->get($url)->assertForbidden();

        Carbon::setTestNow();
    }

    public function test_signierter_link_ersetzt_die_autorisierung_nicht(): void
    {
        $artefakt = $this->erzeugeArtefakt();

        $url = (new SignedDownloadUrlFactory)->forRoute(
            'portal.downloads.signed',
            ['generatedDocument' => (string) $artefakt->getKey()]
        );

        $fremd = $this->makeWorld();

        $this->actingAs($fremd['user'])->get($url)->assertNotFound();
    }

    public function test_ersetztes_artefakt_wird_nicht_mehr_ausgeliefert(): void
    {
        $artefakt = $this->erzeugeArtefakt();

        $artefakt->forceFill(['status' => GeneratedDocumentStatus::ERSETZT])->save();

        $this->actingAs($this->welt()['user'])
            ->get('/app/downloads/'.$artefakt->getKey())
            ->assertNotFound();
    }

    public function test_artefakt_auf_einer_fremden_disk_wird_nicht_ausgeliefert(): void
    {
        $artefakt = $this->erzeugeArtefakt();

        $artefakt->forceFill(['storage_disk' => 'temporary_uploads'])->save();

        $this->actingAs($this->welt()['user'])
            ->get('/app/downloads/'.$artefakt->getKey())
            ->assertNotFound();
    }

    private function erzeugeArtefakt(): GeneratedDocument
    {
        $artifacts = new ArtifactStorage;

        $referenz = $artifacts->put(
            ArtifactType::MIETERABRECHNUNG_FINAL,
            (string) $this->welt()['organization']->getKey(),
            SampleFiles::pdf(2)
        );

        $artefakt = new GeneratedDocument;

        $artefakt->fill([
            'organization_id' => $this->welt()['organization']->getKey(),
            'billing_run_id' => $this->welt()['billingRun']->getKey(),
            'kind' => GeneratedDocumentKind::MIETERABRECHNUNG,
            'variant' => GeneratedDocumentVariant::FINAL,
            'status' => GeneratedDocumentStatus::AKTIV,
            'storage_disk' => $referenz->disk,
            'storage_path' => $referenz->path,
            'byte_size' => $referenz->byteSize,
            'sha256' => $referenz->sha256,
            'generated_at' => Carbon::now(),
        ]);

        $artefakt->save();

        return $artefakt;
    }
}
