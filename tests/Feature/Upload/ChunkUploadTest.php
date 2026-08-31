<?php

declare(strict_types=1);

namespace Tests\Feature\Upload;

use App\Enums\DocumentProcessingStatus;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Prueft den Chunk-Upload ueber die HTTP-Routen: Start, Annahme, Wiederaufnahme,
 * doppelter Abschnitt, fehlender Abschnitt und Abschluss (Abschnitt 6.1).
 */
class ChunkUploadTest extends TestCase
{
    use BuildsUploadWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpUploadWorld();

        config([
            'smartabrechnen.uploads.max_file_mb' => 25,
            'smartabrechnen.uploads.max_run_mb' => 250,
            'smartabrechnen.uploads.chunk_size_mb' => 1,
        ]);
    }

    public function test_start_liefert_abschnittsgroesse_und_neutrale_quellenbezeichnung(): void
    {
        $antwort = $this->starteUpload('Grundsteuerbescheid Familie Beispiel.pdf', 4096);

        $antwort->assertCreated();
        $antwort->assertJsonStructure([
            'upload_id', 'dokument_id', 'quellenbezeichnung', 'abschnitte',
            'abschnittsgroesse', 'geloescht_spaetestens',
        ]);

        $this->assertSame('Dokument 01 - Nicht klassifiziert', $antwort->json('quellenbezeichnung'));
        $this->assertSame(1, $antwort->json('abschnitte'));
    }

    public function test_der_originaldateiname_wird_nirgends_gespeichert(): void
    {
        $this->starteUpload('Mietvertrag Familie Beispielmann 2026.pdf', 4096)->assertCreated();

        $dokument = Document::query()->firstOrFail();
        $upload = TemporaryUpload::query()->firstOrFail();

        $gespeichert = json_encode([$dokument->getAttributes(), $upload->getAttributes()], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Beispielmann', $gespeichert);
        $this->assertStringNotContainsString('Mietvertrag Familie', $gespeichert);
        $this->assertStringNotContainsString('.pdf', $gespeichert);
    }

    public function test_nimmt_abschnitte_an_und_meldet_die_fehlenden(): void
    {
        $antwort = $this->starteUpload('unterlage.pdf', 3 * 1024 * 1024);
        $uploadId = (string) $antwort->json('upload_id');

        $this->assertSame(3, $antwort->json('abschnitte'));

        $erster = $this->sendeAbschnitt($uploadId, 0, str_repeat('A', 1024 * 1024));

        $erster->assertOk();
        $erster->assertJsonPath('empfangene_abschnitte', 1);
        $erster->assertJsonPath('fehlende_abschnitte', [1, 2]);
        $erster->assertJsonPath('vollstaendig', false);
    }

    public function test_doppelt_gesendeter_abschnitt_veraendert_nichts(): void
    {
        $antwort = $this->starteUpload('unterlage.pdf', 2 * 1024 * 1024);
        $uploadId = (string) $antwort->json('upload_id');

        $inhalt = str_repeat('A', 1024 * 1024);

        $erster = $this->sendeAbschnitt($uploadId, 0, $inhalt);
        $zweiter = $this->sendeAbschnitt($uploadId, 0, $inhalt);

        $erster->assertJsonPath('bereits_vorhanden', false);
        $zweiter->assertJsonPath('bereits_vorhanden', true);
        $zweiter->assertJsonPath('empfangene_abschnitte', 1);
        $zweiter->assertJsonPath('empfangene_bytes', 1024 * 1024);
    }

    public function test_wiederaufnahme_nach_abbruch_uebertraegt_nur_die_fehlenden_abschnitte(): void
    {
        $antwort = $this->starteUpload('unterlage.pdf', 3 * 1024 * 1024);
        $uploadId = (string) $antwort->json('upload_id');

        $this->sendeAbschnitt($uploadId, 0, str_repeat('A', 1024 * 1024))->assertOk();
        $this->sendeAbschnitt($uploadId, 2, str_repeat('C', 1024 * 1024))->assertOk();

        // Der Browser fragt nach einem Abbruch den Zustand ab.
        $zustand = $this->actingAs($this->welt()['user'])->getJson('/app/uploads/'.$uploadId);

        $zustand->assertOk();
        $zustand->assertJsonPath('fehlende_abschnitte', [1]);
        $zustand->assertJsonPath('vollstaendig', false);

        $this->sendeAbschnitt($uploadId, 1, str_repeat('B', 1024 * 1024))->assertOk();

        $this->actingAs($this->welt()['user'])
            ->getJson('/app/uploads/'.$uploadId)
            ->assertJsonPath('vollstaendig', true);
    }

    public function test_abschluss_ohne_alle_abschnitte_wird_abgelehnt(): void
    {
        $antwort = $this->starteUpload('unterlage.pdf', 2 * 1024 * 1024);
        $uploadId = (string) $antwort->json('upload_id');

        $this->sendeAbschnitt($uploadId, 0, str_repeat('A', 1024 * 1024))->assertOk();

        $abschluss = $this->schliesseUploadAb($uploadId, 'pdf');

        $abschluss->assertStatus(422);
        $abschluss->assertJsonPath('fehlercode', UploadErrorCode::CHUNK_FEHLT->value);
        $abschluss->assertJsonPath('fehlende_abschnitte', [1]);
    }

    public function test_ungueltiger_abschnittsindex_wird_abgelehnt(): void
    {
        $antwort = $this->starteUpload('unterlage.pdf', 1024);
        $uploadId = (string) $antwort->json('upload_id');

        $this->sendeAbschnitt($uploadId, 5, 'ABC')
            ->assertStatus(422)
            ->assertJsonPath('fehlercode', UploadErrorCode::CHUNK_INDEX_UNGUELTIG->value);
    }

    public function test_abschluss_stellt_die_verarbeitung_ein(): void
    {
        $inhalt = SampleFiles::pdf(2);

        $upload = $this->ladeDateiHoch($inhalt, 'pdf');

        $dokument = $upload->document;

        $this->assertInstanceOf(Document::class, $dokument);
        $this->assertSame(DocumentProcessingStatus::SICHERHEITSPRUEFUNG, $dokument->getAttribute('processing_status'));

        $this->assertDatabaseHas('processing_jobs', [
            'job_type' => 'dokument.zusammensetzen',
            'document_id' => $dokument->getKey(),
        ]);
    }

    public function test_abgelaufener_upload_nimmt_keine_abschnitte_mehr_an(): void
    {
        $antwort = $this->starteUpload('unterlage.pdf', 1024);
        $uploadId = (string) $antwort->json('upload_id');

        TemporaryUpload::query()->whereKey($uploadId)->update([
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->sendeAbschnitt($uploadId, 0, 'ABC')
            ->assertStatus(422)
            ->assertJsonPath('fehlercode', UploadErrorCode::UPLOAD_ABGELAUFEN->value);
    }

    public function test_fremder_mandant_darf_nicht_hochladen(): void
    {
        $antwort = $this->starteUpload('unterlage.pdf', 1024);
        $uploadId = (string) $antwort->json('upload_id');

        $fremd = $this->makeWorld();

        $this->actingAs($fremd['user'])
            ->postJson('/app/uploads/'.$uploadId.'/abschnitte', ['index' => 0])
            ->assertForbidden();

        $this->actingAs($fremd['user'])
            ->getJson('/app/uploads/'.$uploadId)
            ->assertForbidden();
    }

    public function test_abschnitte_liegen_ausschliesslich_im_kurzzeitbereich(): void
    {
        $antwort = $this->starteUpload('unterlage.pdf', 1024);
        $uploadId = (string) $antwort->json('upload_id');

        $this->sendeAbschnitt($uploadId, 0, str_repeat('A', 1024))->assertOk();

        $upload = TemporaryUpload::query()->findOrFail($uploadId);
        $prefix = (string) $upload->getAttribute('storage_key');

        $this->assertNotSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(TemporaryUploadStorage::DISK, $upload->getAttribute('storage_disk'));
    }

    public function test_unzulaessige_dateiendung_wird_bereits_beim_start_abgelehnt(): void
    {
        $this->starteUpload('schadcode.exe', 1024)
            ->assertStatus(422)
            ->assertJsonPath('fehlercode', UploadErrorCode::ERWEITERUNG_UNZULAESSIG->value);
    }

    public function test_optionale_kategorie_wird_uebernommen(): void
    {
        $antwort = $this->starteUpload('unterlage.pdf', 1024, 'GRUNDSTEUERBESCHEID');

        $antwort->assertCreated();
        $this->assertSame('Dokument 01 - Grundsteuerbescheid', $antwort->json('quellenbezeichnung'));

        $dokument = Document::query()->firstOrFail();

        $this->assertTrue($dokument->getAttribute('type_assigned_manually'));
    }
}
