<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\DeletionStatus;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Enums\ExtractedFieldStatus;
use App\Models\AiPromptVersion;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\ExtractedField;
use App\Models\TemporaryUpload;
use App\Providers\AiServiceProvider;
use App\Services\Ai\Integration\ExtractedFieldPersister;
use App\Services\Storage\TemporaryFileKind;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\Feature\Upload\Concerns\ProviderLoeschProtokoll;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Vollstaendiger Durchlauf der Dokumentpipeline mit der echten Verdrahtung
 * aus AiServiceProvider und dem Testprovider ohne Netzwerkaufruf.
 *
 * Geprueft wird der Nachweis aus Abschnitt 6.3 und 6.4: nach dem Lauf sind die
 * strukturierten Felder vorhanden und die Quelldaten vollstaendig geloescht.
 *
 * Es findet KEIN Providerzugriff statt. Alle Beispielantworten sind frei
 * erfunden und liegen in tests/Fixtures/Ai.
 */
class DocumentPipelineIntegrationTest extends TestCase
{
    use BuildsUploadWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpUploadWorld();

        ProviderLoeschProtokoll::zuruecksetzen();

        config([
            'smartabrechnen.uploads.max_file_mb' => 25,
            'smartabrechnen.uploads.max_run_mb' => 250,
            'smartabrechnen.uploads.chunk_size_mb' => 1,
            // Die Testsuite bindet die KI-Anbindung ausdruecklich, damit der
            // Zustand ohne KI-Anbindung in anderen Tests pruefbar bleibt.
            'ai.bind_document_pipeline' => true,
        ]);

        $this->app->register(new AiServiceProvider($this->app), true);
    }

    public function test_vollstaendiger_durchlauf_persistiert_felder_und_loescht_die_quelldaten(): void
    {
        $upload = $this->ladeDateiHoch(SampleFiles::pdf(3), 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        // Die auswertende Schicht legt waehrend der Verarbeitung Ableitungen an.
        $storage = new TemporaryUploadStorage;
        $storage->putDerivative($prefix, TemporaryFileKind::SEITENBILD, 'seite-1.png', SampleFiles::png());
        $storage->putDerivative($prefix, TemporaryFileKind::OCR_TEXT, 'volltext.txt', 'Vollstaendiger OCR-Text der Unterlage');

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        // 1. Der Lauf ist abgeschlossen.
        $this->assertSame(DocumentProcessingStatus::ABGESCHLOSSEN, $dokument->getAttribute('processing_status'));
        $this->assertNotNull($dokument->getAttribute('extracted_at'));

        // 2. Die strukturierten Felder sind da.
        $felder = ExtractedField::query()->where('document_id', $dokument->getKey())->get();

        $this->assertGreaterThan(20, $felder->count());
        $this->assertSame(
            ['wert' => 372000],
            ExtractedField::query()
                ->where('document_id', $dokument->getKey())
                ->where('schema_key', 'hausgeldvorauszahlungen_cent')
                ->value('value'),
        );

        // 3. Original, Seitenbild und OCR-Text sind fort.
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
        $this->assertSame(0, $storage->countFiles($prefix));

        // 4. Der Kurzzeitdatensatz ist ein inhaltsloser Tombstone.
        $upload->refresh();

        $this->assertTrue($upload->getAttribute('is_tombstone'));
        $this->assertNull($upload->getAttribute('storage_key'));
        $this->assertSame(DeletionStatus::ERFOLGREICH, $dokument->getAttribute('deletion_status'));
    }

    public function test_klassifikation_setzt_dokumentart_konfidenz_und_quellenbezeichnung(): void
    {
        $this->ladeDateiHoch(SampleFiles::pdf(3), 'pdf');

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, $dokument->getAttribute('document_type'));
        $this->assertSame('0.9400', $dokument->getAttribute('document_type_confidence'));
        $this->assertSame(
            'Dokument 01 - WEG-Hausgeldabrechnung Einzelabrechnung',
            $dokument->getAttribute('source_label'),
        );
        $this->assertNotNull($dokument->getAttribute('classified_at'));
    }

    public function test_jedes_feld_traegt_seite_und_kurze_fundstelle(): void
    {
        $this->ladeDateiHoch(SampleFiles::pdf(3), 'pdf');

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $feld = ExtractedField::query()
            ->where('document_id', $dokument->getKey())
            ->where('schema_key', 'abrechnungsspitze_cent')
            ->firstOrFail();

        $this->assertSame(2, $feld->getAttribute('page_number'));
        $this->assertSame('Nachzahlung 184,50', $feld->getAttribute('source_excerpt'));
        $this->assertSame('0.9400', $feld->getAttribute('confidence'));
        $this->assertSame(ExtractedFieldStatus::AUTOMATISCH_ERKANNT, $feld->getAttribute('status'));

        // Der Quellenbezug haengt an einer echten Seitenreferenz.
        $seite = DocumentPage::query()
            ->where('document_id', $dokument->getKey())
            ->where('page_number', 2)
            ->firstOrFail();

        $this->assertSame($seite->getKey(), $feld->getAttribute('document_page_id'));
        $this->assertTrue($seite->getAttribute('has_structured_findings'));
        $this->assertGreaterThan(0, (int) $seite->getAttribute('extracted_field_count'));
    }

    public function test_persistierte_felder_enthalten_keinen_volltext_und_keine_rohantwort(): void
    {
        $this->ladeDateiHoch(SampleFiles::pdf(3), 'pdf');

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();
        $felder = ExtractedField::query()->where('document_id', $dokument->getKey())->get();

        $this->assertNotEmpty($felder);

        foreach ($felder as $feld) {
            $ausschnitt = $feld->getAttribute('source_excerpt');

            if ($ausschnitt === null) {
                continue;
            }

            $this->assertIsString($ausschnitt);
            $this->assertLessThanOrEqual(
                ExtractedFieldPersister::MAX_SOURCE_EXCERPT_LENGTH,
                mb_strlen($ausschnitt),
                'Ein Fundstellenausschnitt ueberschreitet die defensive Laengengrenze.'
            );

            // Struktur der Rohantwort darf nirgends auftauchen.
            $this->assertStringNotContainsString('"confidence"', $ausschnitt);
            $this->assertStringNotContainsString('"source_page"', $ausschnitt);
        }

        // Der vollstaendige OCR-Text und die Rohantwort sind nirgends gelandet.
        $gesamt = $felder->map(
            static fn (ExtractedField $feld): string => json_encode($feld->getAttribute('value')).'|'
                .(string) $feld->getAttribute('source_excerpt')
        )->implode("\n");

        $this->assertStringNotContainsString('Vollstaendiger OCR-Text', $gesamt);
        $this->assertStringNotContainsString('%PDF', $gesamt);
        $this->assertStringNotContainsString('kostenaufschluesselung_vorhanden"', $gesamt);
    }

    public function test_promptversion_wird_je_zweck_protokolliert(): void
    {
        $this->ladeDateiHoch(SampleFiles::pdf(3), 'pdf');

        $this->verarbeiteQueue();

        $versionen = AiPromptVersion::query()->get();

        $this->assertGreaterThanOrEqual(2, $versionen->count());
        $this->assertNotNull(
            AiPromptVersion::query()->where('purpose', 'KLASSIFIKATION')->first(),
            'Der Klassifikationsprompt muss versioniert protokolliert sein.'
        );
        $this->assertNotNull(
            AiPromptVersion::query()->where('purpose', 'EXTRAKTION')->first(),
            'Der Extraktionsprompt muss versioniert protokolliert sein.'
        );

        foreach ($versionen as $version) {
            $this->assertSame(64, mb_strlen((string) $version->getAttribute('hash')));
            $this->assertTrue($version->getAttribute('is_active'));
        }
    }

    public function test_dokument_ohne_verwertbaren_upload_wird_abgelehnt_und_hinterlaesst_nichts(): void
    {
        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');
        $prefix = (string) $upload->getAttribute('storage_key');

        // Die Quelldatei verschwindet zwischen Zusammensetzung und Auswertung,
        // zum Beispiel durch den unabhaengigen TTL-Cleanup.
        Storage::disk(TemporaryUploadStorage::DISK)->deleteDirectory($prefix);

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertNotSame(DocumentProcessingStatus::ABGESCHLOSSEN, $dokument->getAttribute('processing_status'));
        $this->assertSame(0, ExtractedField::query()->where('document_id', $dokument->getKey())->count());
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
    }

    public function test_ohne_zuordenbare_dokumentart_bleibt_die_unterlage_manuell_zuzuordnen(): void
    {
        $upload = $this->ladeDateiHoch(SampleFiles::pdf(2), 'pdf');

        // Eine bereits manuell gesetzte Art hat Vorrang und wird nicht
        // ueberschrieben. SONSTIGES hat kein Extraktionsschema, deshalb muss
        // der Lauf mit einer manuellen Erfassung enden statt zu raten.
        Document::query()->whereKey($upload->getAttribute('document_id'))->update([
            'document_type' => DocumentType::SONSTIGES->value,
            'type_assigned_manually' => true,
        ]);

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        $this->assertSame(DocumentType::SONSTIGES, $dokument->getAttribute('document_type'));
        $this->assertSame(DocumentProcessingStatus::FEHLGESCHLAGEN, $dokument->getAttribute('processing_status'));
        $this->assertSame('KLASSIFIKATION_FEHLGESCHLAGEN', $dokument->getAttribute('failure_code'));
        $this->assertSame(0, ExtractedField::query()->count());

        // Auch dieser endgueltige Fehler loescht die Quelldaten sofort.
        $this->assertSame(DeletionStatus::ERFOLGREICH, $dokument->getAttribute('deletion_status'));
        $this->assertTrue(TemporaryUpload::query()->firstOrFail()->getAttribute('is_tombstone'));
    }
}
