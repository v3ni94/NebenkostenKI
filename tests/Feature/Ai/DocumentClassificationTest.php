<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiCallPurpose;
use App\Enums\DocumentType;
use App\Models\ExtractedField;
use App\Services\Ai\Integration\DocumentSchemaMap;
use App\Services\Ai\Schemas\SchemaRegistry;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Ai\Concerns\BuildsAiIntegration;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Nachweise zur Klassifikation und zur Schemazuordnung nach Abschnitt 6.2.
 *
 * Grundsatz 5 gilt hier besonders: Ein nicht zuordenbarer Typ wird nicht
 * geraten, sondern als manuelle Aufgabe zurueckgegeben.
 */
class DocumentClassificationTest extends TestCase
{
    use BuildsAiIntegration, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiIntegration();
    }

    public function test_klassifikation_liefert_dokumentart_und_konfidenz(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $outcome = $this->classifier($this->router($this->testProvider()))->classify($document, $upload);

        $this->assertTrue($outcome->successful);
        $this->assertSame(DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, $outcome->documentType);
        $this->assertSame(0.94, $outcome->confidence);
        $this->assertNull($outcome->errorCode);
    }

    public function test_nicht_zuordenbare_unterlage_wird_zur_manuellen_aufgabe(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $provider = $this->testProvider([
            'dokumentklassifikation' => 'hausgeldabrechnung.json',
        ]);

        $outcome = $this->classifier($this->router($provider))->classify($document, $upload);

        // Die Antwort passt nicht zum Klassifikationsschema. Es wird nichts
        // geraten: die Unterlage bleibt SONSTIGES und der Nutzer ordnet zu.
        $this->assertFalse($outcome->successful);
        $this->assertSame(DocumentType::SONSTIGES, $outcome->documentType);
        $this->assertSame('SCHEMA_UNGUELTIG', $outcome->errorCode);
        $this->assertNull($outcome->confidence);
    }

    public function test_anweisungstext_im_dokument_wird_gemeldet_aber_nicht_befolgt(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $provider = $this->testProvider([
            'dokumentklassifikation' => 'dokumentklassifikation_prompt_injection.json',
        ]);

        $outcome = $this->classifier($this->router($provider))->classify($document, $upload);

        // Der Anweisungstext aendert das Ergebnis nicht: der erkannte Typ wird
        // regulaer uebernommen, die Anweisung selbst wird nicht befolgt und
        // nicht gespeichert.
        $this->assertTrue($outcome->successful);
        $this->assertSame(DocumentType::RECHNUNG, $outcome->documentType);
        $this->assertSame(0, ExtractedField::query()->count());
    }

    public function test_fehlende_quelldatei_fuehrt_zu_einem_klaren_fehlercode(): void
    {
        [$document, $upload, $prefix] = $this->dokumentMitQuelldatei();

        Storage::disk(TemporaryUploadStorage::DISK)->deleteDirectory($prefix);

        $outcome = $this->classifier($this->router($this->testProvider()))->classify($document, $upload);

        $this->assertFalse($outcome->successful);
        $this->assertNull($outcome->documentType);
        $this->assertSame('QUELLE_NICHT_VORHANDEN', $outcome->errorCode);
    }

    public function test_nicht_auswertbares_dateiformat_wird_abgelehnt(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei(
            DocumentType::MIETER_EINHEITENLISTE,
            'PK'."\x03\x04".'Beispielinhalt einer Tabellenkalkulation',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $outcome = $this->extractor($this->router($this->testProvider()))->extract($document, $upload);

        $this->assertFalse($outcome->successful);
        $this->assertTrue($outcome->permanent);
        $this->assertSame('ERWEITERUNG_UNZULAESSIG', $outcome->errorCode);
    }

    public function test_csv_wird_als_reiner_text_uebergeben(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei(
            DocumentType::MIETER_EINHEITENLISTE,
            "einheit;flaeche\nWohnung 1;72,40\n",
            'text/csv',
        );

        $outcome = $this->extractor($this->router($this->testProvider()))->extract($document, $upload);

        $this->assertTrue($outcome->successful);
        $this->assertGreaterThan(0, $outcome->persistedFieldCount);
    }

    public function test_schemazuordnung_deckt_jede_dokumentart_ab(): void
    {
        $map = new DocumentSchemaMap(new SchemaRegistry);

        foreach (DocumentType::cases() as $type) {
            $schemaKey = $map->schemaKeyFor($type);

            if ($type === DocumentType::SONSTIGES) {
                $this->assertNull($schemaKey, 'SONSTIGES darf kein Schema erhalten, sonst wuerde geraten.');

                continue;
            }

            $this->assertIsString($schemaKey, sprintf('Fuer %s fehlt die Schemazuordnung.', $type->value));
            $this->assertTrue(
                (new SchemaRegistry)->has($schemaKey),
                sprintf('Der Schemaschluessel "%s" ist nicht hinterlegt.', $schemaKey),
            );
        }
    }

    public function test_vertraege_und_vorjahresabrechnungen_laufen_ueber_die_analysemethoden(): void
    {
        $map = new DocumentSchemaMap(new SchemaRegistry);

        $this->assertSame(AiCallPurpose::VERTRAGSANALYSE, $map->purposeFor(DocumentType::MIETVERTRAG));
        $this->assertSame(AiCallPurpose::VERTRAGSANALYSE, $map->purposeFor(DocumentType::MIETVERTRAG_NACHTRAG));
        $this->assertTrue($map->isAmendment(DocumentType::MIETVERTRAG_NACHTRAG));
        $this->assertFalse($map->isAmendment(DocumentType::MIETVERTRAG));
        $this->assertSame(AiCallPurpose::VORJAHRESANALYSE, $map->purposeFor(DocumentType::VORJAHRESABRECHNUNG));
        $this->assertSame(AiCallPurpose::EXTRAKTION, $map->purposeFor(DocumentType::GRUNDSTEUERBESCHEID));
    }

    public function test_mietvertrag_wird_ueber_die_vertragsanalyse_ausgewertet(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei(DocumentType::MIETVERTRAG, SampleFiles::pdf(4), 'application/pdf', 4);

        $outcome = $this->extractor($this->router($this->testProvider()))->extract($document, $upload);

        $this->assertTrue($outcome->successful);
        $this->assertGreaterThan(0, $outcome->persistedFieldCount);
        $this->assertNotNull(
            ExtractedField::query()->where('document_id', $document->getKey())->first(),
        );
    }
}
