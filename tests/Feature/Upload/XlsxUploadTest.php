<?php

declare(strict_types=1);

namespace Tests\Feature\Upload;

use App\Models\Document;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * XLSX wird fuer den Start nicht ausgewertet (Entscheidung der Steuerung).
 * Die Annahme wird deshalb bereits beim Start des Uploads mit einer klaren
 * Handlungsanweisung abgelehnt, statt dass die Datei die Pruefkette durchlaeuft
 * und erst in der Auswertung scheitert.
 */
class XlsxUploadTest extends TestCase
{
    use BuildsUploadWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpUploadWorld();

        config(['smartabrechnen.uploads.malware_scanner.driver' => 'disabled']);
    }

    public function test_xlsx_wird_beim_start_des_uploads_mit_handlungsanweisung_abgelehnt(): void
    {
        $antwort = $this->starteUpload('mieterliste.xlsx', 4096);

        $antwort->assertStatus(422);
        $antwort->assertJsonValidationErrors('dateiname');

        $meldung = (string) $antwort->json('errors.dateiname.0');

        $this->assertStringContainsString('XLSX', $meldung);
        $this->assertStringContainsString('CSV', $meldung, 'Die Meldung muss den funktionierenden Weg nennen.');
        $this->assertStringNotContainsString('mieterliste', $meldung, 'Der Dateiname darf nicht zurueckgegeben werden.');

        $this->assertSame(0, Document::query()->count(), 'Es darf kein Dokument angelegt werden.');
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles());
    }

    public function test_grossschreibung_der_endung_aendert_nichts(): void
    {
        $this->starteUpload('Mieterliste.XLSX', 4096)
            ->assertStatus(422)
            ->assertJsonValidationErrors('dateiname');
    }

    public function test_csv_bleibt_zulaessig(): void
    {
        $this->starteUpload('mieterliste.csv', 4096)->assertCreated();
    }

    /**
     * Befund N14: Ein Archiv mit brauchbaren PDFs und einer beigelegten
     * Tabelle wird nicht mehr vollstaendig abgelehnt. Der XLSX-Eintrag wird
     * uebersprungen, die PDF-Eintraege werden entpackt.
     */
    public function test_archiv_mit_xlsx_eintrag_ueberspringt_die_tabelle_und_entpackt_die_pdfs(): void
    {
        $this->ladeDateiHoch(SampleFiles::zip([
            'bescheid.pdf' => SampleFiles::pdf(2),
            'mieterliste.xlsx' => SampleFiles::xlsx(2),
        ]), 'zip');

        $this->verarbeiteQueue();

        $archiv = Document::query()->orderBy('sequence_number')->firstOrFail();

        $this->assertNotSame(
            UploadErrorCode::ARCHIV_EINTRAG_UNZULAESSIG->value,
            $archiv->getAttribute('failure_code'),
            'Die Tabelle darf das Archiv nicht mehr zu Fall bringen.'
        );

        $this->assertSame(2, Document::query()->count(), 'Archiv und ein entpackter PDF-Eintrag.');
        $this->assertSame(
            0,
            Document::query()->where('mime_type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->count(),
            'Aus der Tabelle entsteht kein Dokument.'
        );
        $this->assertSame(1, Document::query()->where('mime_type', 'application/pdf')->count());
    }

    public function test_die_konfiguration_bietet_xlsx_nicht_mehr_an(): void
    {
        $this->assertNotContains(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (array) config('smartabrechnen.uploads.accepted_mime_types')
        );
    }

    public function test_fehlermeldungen_bewerben_xlsx_nicht_mehr_als_zulaessig(): void
    {
        $this->assertStringNotContainsString('XLSX', UploadErrorCode::ERWEITERUNG_UNZULAESSIG->message());
        $this->assertStringNotContainsString('XLSX', UploadErrorCode::MIME_UNBEKANNT->message());
        $this->assertStringContainsString('CSV', UploadErrorCode::ERWEITERUNG_UNZULAESSIG->message());
    }
}
