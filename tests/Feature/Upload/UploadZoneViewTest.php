<?php

declare(strict_types=1);

namespace Tests\Feature\Upload;

use App\Enums\DocumentProcessingStatus;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Prueft die Uploadzone und die Statusliste (Abschnitt 9 Schritt 2, 6.4).
 */
class UploadZoneViewTest extends TestCase
{
    use BuildsUploadWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpUploadWorld();
    }

    public function test_uploaddialog_zeigt_den_verbindlichen_loeschhinweis(): void
    {
        $antwort = $this->actingAs($this->welt()['user'])
            ->get('/app/abrechnungen/'.$this->welt()['billingRun']->getKey().'/upload');

        $antwort->assertOk();

        // Der Satz ist im Template umbrochen. Geprueft wird deshalb die
        // Reihenfolge der Bestandteile, nicht die Zeilenfuehrung.
        $antwort->assertSeeInOrder([
            'Ihre Originaldateien werden nur zur Auswertung kurzfristig verarbeitet und anschließend',
            'automatisch gelöscht. Bitte bewahren Sie Ihre Originalbelege selbst auf.',
        ], false);
    }

    public function test_uploadzone_nimmt_alle_dokumentarten_an_und_die_kategorie_ist_optional(): void
    {
        $antwort = $this->actingAs($this->welt()['user'])
            ->get('/app/abrechnungen/'.$this->welt()['billingRun']->getKey().'/upload');

        $antwort->assertOk();
        $antwort->assertSee('data-upload-zone', false);
        $antwort->assertSee('data-upload-dropzone', false);
        $antwort->assertSee('Kategorie (optional)', false);
        $antwort->assertSee('Automatisch erkennen', false);
        $antwort->assertSee('PDF, JPG, PNG, HEIC, CSV, XLSX und ZIP', false);
    }

    public function test_statusliste_zeigt_neutrale_bezeichnung_und_niemals_den_dateinamen(): void
    {
        $this->starteUpload('Grundsteuer Familie Beispielmann.pdf', 1024)->assertCreated();

        $antwort = $this->actingAs($this->welt()['user'])
            ->get('/app/abrechnungen/'.$this->welt()['billingRun']->getKey().'/upload');

        $antwort->assertOk();
        $antwort->assertSee('Dokument 01 - Nicht klassifiziert');
        $antwort->assertDontSee('Beispielmann');
        $antwort->assertSee('Originaldatei');
    }

    public function test_statusabfrage_liefert_den_verarbeitungsstand_je_dokument(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $this->verarbeiteQueue();

        $antwort = $this->actingAs($this->welt()['user'])
            ->getJson('/app/abrechnungen/'.$this->welt()['billingRun']->getKey().'/uploads/status');

        $antwort->assertOk();
        $antwort->assertJsonPath('dokumente.0.status', DocumentProcessingStatus::ABGESCHLOSSEN->value);
        $antwort->assertJsonPath('dokumente.0.statustext', 'Auswertung abgeschlossen');
        $antwort->assertJsonPath('dokumente.0.original_geloescht', true);
        $antwort->assertJsonPath('dokumente.0.loeschstatustext', 'Gelöscht');
    }

    public function test_statusabfrage_enthaelt_keinen_storage_key(): void
    {
        $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');

        $antwort = $this->actingAs($this->welt()['user'])
            ->getJson('/app/abrechnungen/'.$this->welt()['billingRun']->getKey().'/uploads/status');

        $inhalt = $antwort->getContent();

        $this->assertIsString($inhalt);
        $this->assertStringNotContainsString('quarantaene/', $inhalt);
        $this->assertStringNotContainsString('storage_key', $inhalt);
        $this->assertStringNotContainsString('.pdf', $inhalt);
    }

    public function test_fremder_mandant_sieht_die_uploadzone_nicht(): void
    {
        $fremd = $this->makeWorld();

        $this->actingAs($fremd['user'])
            ->get('/app/abrechnungen/'.$this->welt()['billingRun']->getKey().'/upload')
            ->assertForbidden();

        $this->actingAs($fremd['user'])
            ->getJson('/app/abrechnungen/'.$this->welt()['billingRun']->getKey().'/uploads/status')
            ->assertForbidden();
    }

    public function test_fehlermeldungen_erscheinen_verstaendlich_in_der_statusliste(): void
    {
        $this->ladeDateiHoch(SampleFiles::png(), 'pdf');
        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DocumentProcessingStatus::ABGELEHNT, $dokument->getAttribute('processing_status'));

        $antwort = $this->actingAs($this->welt()['user'])
            ->get('/app/abrechnungen/'.$this->welt()['billingRun']->getKey().'/upload');

        $antwort->assertOk();
        $antwort->assertSee('Der Inhalt der Datei passt nicht zur Dateiendung.', false);
    }
}
