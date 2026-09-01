<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Application\Documents\Contracts\DocumentExtractor;
use App\Application\Documents\StartExtraction;
use App\Enums\AiCallPurpose;
use App\Enums\AiCallStatus;
use App\Enums\DeletionStatus;
use App\Enums\DocumentType;
use App\Enums\ExtractedFieldStatus;
use App\Enums\ValidationIssueStatus;
use App\Models\ExtractedField;
use App\Models\TemporaryUpload;
use App\Models\ValidationIssue;
use App\Services\Ai\Dto\AiCallMetadata;
use App\Services\Ai\Dto\AiResultStatus;
use App\Services\Ai\Dto\ExtractedValue;
use App\Services\Ai\Dto\ExtractionResult;
use App\Services\Ai\Integration\ExtractedFieldPersister;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Ai\Concerns\BuildsAiIntegration;
use Tests\TestCase;

/**
 * Nachweise zu Abschnitt 6.4 und 6.5: was dauerhaft gespeichert wird, wie
 * fehlende und unsichere Werte behandelt werden und dass ein erneuter Lauf
 * nichts verdoppelt.
 */
class ExtractedFieldPersistenceTest extends TestCase
{
    use BuildsAiIntegration, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiIntegration();
    }

    public function test_konfidenz_unter_dem_schwellenwert_markiert_das_feld_als_pruefpflichtig(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $outcome = $this->extractor($this->router($this->testProvider()))->extract($document, $upload);

        $this->assertTrue($outcome->successful);

        $unsicher = ExtractedField::query()
            ->where('document_id', $document->getKey())
            ->where('schema_key', 'kostenarten[3].verwalter_kennzeichnung_umlagefaehig')
            ->firstOrFail();

        $this->assertSame('0.5500', $unsicher->getAttribute('confidence'));

        $pruefpflichtig = ExtractedField::query()
            ->where('document_id', $document->getKey())
            ->needsReview()
            ->pluck('schema_key')
            ->all();

        $this->assertContains('kostenarten[3].verwalter_kennzeichnung_umlagefaehig', $pruefpflichtig);
        $this->assertContains('kostenarten[3].bezeichnung', $pruefpflichtig);

        // Ein hochkonfidentes Feld bleibt ohne gesonderte Pruefpflicht.
        $this->assertNotContains('abrechnungsspitze_cent', $pruefpflichtig);
    }

    public function test_felder_unter_dem_schwellenwert_erzeugen_eine_pruefaufgabe(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $this->extractor($this->router($this->testProvider()))->extract($document, $upload);

        $aufgabe = ValidationIssue::query()
            ->where('rule_code', ExtractedFieldPersister::RULE_LOW_CONFIDENCE)
            ->firstOrFail();

        $this->assertSame(ValidationIssueStatus::OFFEN, $aufgabe->getAttribute('status'));
        $this->assertStringContainsString('Konfidenzschwelle', (string) $aufgabe->getAttribute('description'));
        $this->assertStringContainsString(
            'kostenarten[3].bezeichnung',
            (string) $aufgabe->getAttribute('description'),
        );
    }

    public function test_fehlender_wert_bleibt_null_und_erzeugt_eine_pruefaufgabe(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $this->extractor($this->router($this->testProvider()))->extract($document, $upload);

        $feld = ExtractedField::query()
            ->where('document_id', $document->getKey())
            ->where('schema_key', 'grundsteuer_enthalten')
            ->firstOrFail();

        // Es wird nichts geschaetzt: der Wert bleibt null, die Konfidenz auch.
        $this->assertSame(['wert' => null], $feld->getAttribute('value'));
        $this->assertNull($feld->getAttribute('confidence'));

        $aufgabe = ValidationIssue::query()
            ->where('rule_code', ExtractedFieldPersister::RULE_MISSING_VALUE)
            ->where('entity_id', $feld->getKey())
            ->firstOrFail();

        $this->assertSame(ValidationIssueStatus::OFFEN, $aufgabe->getAttribute('status'));
        $this->assertSame(ExtractedField::class, $aufgabe->getAttribute('entity_type'));
        $this->assertStringContainsString('nicht geschätzt', (string) $aufgabe->getAttribute('description'));

        // Fuer jeden fehlenden Wert der Beispielantwort gibt es genau eine Aufgabe.
        $this->assertSame(
            2,
            ValidationIssue::query()->where('rule_code', ExtractedFieldPersister::RULE_MISSING_VALUE)->count(),
        );
    }

    public function test_langer_fundstellenausschnitt_wird_defensiv_gekuerzt(): void
    {
        [$document] = $this->dokumentMitQuelldatei(DocumentType::RECHNUNG);

        $langerText = str_repeat('Ein vollstaendiger Absatz aus der Unterlage. ', 30);

        $this->assertGreaterThan(500, mb_strlen($langerText));

        $this->persister()->persist(
            $document,
            $this->ergebnisMitFeldern([
                'belegnummer' => new ExtractedValue('belegnummer', 'RG-2025-0042', 0.96, 1, $langerText),
            ]),
            'rechnung_bescheid',
            '1.0.0',
            null,
        );

        $ausschnitt = ExtractedField::query()
            ->where('document_id', $document->getKey())
            ->where('schema_key', 'belegnummer')
            ->value('source_excerpt');

        $this->assertIsString($ausschnitt);
        $this->assertSame(ExtractedFieldPersister::MAX_SOURCE_EXCERPT_LENGTH, mb_strlen($ausschnitt));
        $this->assertStringEndsWith(ExtractedFieldPersister::TRUNCATION_MARKER, $ausschnitt);
    }

    public function test_zweiter_extraktionslauf_erzeugt_keine_doppelten_felder(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $extractor = $this->extractor($this->router($this->testProvider()));

        $ersterLauf = $extractor->extract($document, $upload);
        $anzahlNachErstemLauf = ExtractedField::query()->where('document_id', $document->getKey())->count();

        $zweiterLauf = $extractor->extract($document->refresh(), $upload->refresh());

        $this->assertTrue($ersterLauf->successful);
        $this->assertTrue($zweiterLauf->successful);
        $this->assertSame($ersterLauf->persistedFieldCount, $zweiterLauf->persistedFieldCount);
        $this->assertSame(
            $anzahlNachErstemLauf,
            ExtractedField::query()->where('document_id', $document->getKey())->count(),
        );

        // Auch die Pruefaufgaben werden ersetzt und nicht verdoppelt.
        $this->assertSame(
            2,
            ValidationIssue::query()->where('rule_code', ExtractedFieldPersister::RULE_MISSING_VALUE)->count(),
        );
        $this->assertSame(
            1,
            ValidationIssue::query()->where('rule_code', ExtractedFieldPersister::RULE_LOW_CONFIDENCE)->count(),
        );
    }

    public function test_nutzerkorrektur_wird_von_einem_erneuten_lauf_nicht_ueberschrieben(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $extractor = $this->extractor($this->router($this->testProvider()));
        $extractor->extract($document, $upload);

        $feld = ExtractedField::query()
            ->where('document_id', $document->getKey())
            ->where('schema_key', 'abrechnungsspitze_cent')
            ->firstOrFail();

        $feld->forceFill([
            'corrected_value' => ['wert' => 20000],
            'status' => ExtractedFieldStatus::MANUELL_GEAENDERT,
        ])->save();

        $extractor->extract($document->refresh(), $upload->refresh());

        $feld->refresh();

        $this->assertSame(ExtractedFieldStatus::MANUELL_GEAENDERT, $feld->getAttribute('status'));
        $this->assertSame(['wert' => 20000], $feld->getAttribute('corrected_value'));
        // Der maschinelle Wert wird weiterhin nachgefuehrt.
        $this->assertSame(['wert' => 18450], $feld->getAttribute('value'));
    }

    public function test_felder_eines_frueheren_laufs_ohne_entsprechung_werden_entfernt(): void
    {
        [$document] = $this->dokumentMitQuelldatei(DocumentType::RECHNUNG);

        ExtractedField::factory()->create([
            'organization_id' => $document->getAttribute('organization_id'),
            'billing_run_id' => $document->getAttribute('billing_run_id'),
            'document_id' => $document->getKey(),
            'schema_key' => 'veraltetes_feld',
            'status' => ExtractedFieldStatus::AUTOMATISCH_ERKANNT,
        ]);

        $bestaetigt = ExtractedField::factory()->create([
            'organization_id' => $document->getAttribute('organization_id'),
            'billing_run_id' => $document->getAttribute('billing_run_id'),
            'document_id' => $document->getKey(),
            'schema_key' => 'vom_nutzer_bestaetigt',
            'status' => ExtractedFieldStatus::BESTAETIGT,
        ]);

        $this->persister()->persist(
            $document,
            $this->ergebnisMitFeldern([
                'belegnummer' => new ExtractedValue('belegnummer', 'RG-2025-0042', 0.96, 1, 'RG-2025-0042'),
            ]),
            'rechnung_bescheid',
            '1.0.0',
            null,
        );

        $this->assertNull(
            ExtractedField::query()->where('document_id', $document->getKey())->where('schema_key', 'veraltetes_feld')->first(),
        );
        $this->assertNotNull($bestaetigt->fresh(), 'Eine Nutzerbestaetigung darf nicht entfernt werden.');
    }

    public function test_seitenzahl_und_seitenzaehler_werden_gefuehrt(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $outcome = $this->extractor($this->router($this->testProvider()))->extract($document, $upload);

        // Die technisch ermittelte Seitenzahl bleibt massgeblich.
        $this->assertSame(3, $outcome->pageCount);

        $summe = (int) $document->pages()->sum('extracted_field_count');

        $this->assertSame(
            ExtractedField::query()->where('document_id', $document->getKey())->whereNotNull('document_page_id')->count(),
            $summe,
        );
    }

    public function test_schemaverletzung_fuehrt_zu_endgueltigem_fehler_und_loescht_die_quelldaten(): void
    {
        [$document, $upload, $prefix] = $this->dokumentMitQuelldatei(DocumentType::RECHNUNG);

        $provider = $this->testProvider([
            'rechnung_bescheid' => 'rechnung_bescheid_schemaverletzung.json',
        ]);

        $this->app->instance(DocumentExtractor::class, $this->extractor($this->router($provider)));

        $outcome = $this->app->make(StartExtraction::class)($document);

        $this->assertFalse($outcome->successful);
        $this->assertFalse($outcome->schemaValid);
        $this->assertTrue($outcome->permanent, 'Eine Schemaverletzung ist endgueltig, ein erneuter Versuch bringt nichts.');
        $this->assertSame('SCHEMA_UNGUELTIG', $outcome->errorCode);

        // Kein halb geprueftes Ergebnis in der Abrechnung.
        $this->assertSame(0, ExtractedField::query()->where('document_id', $document->getKey())->count());

        // Trotzdem werden die Quelldaten sofort geloescht.
        $document->refresh();

        $this->assertSame(DeletionStatus::ERFOLGREICH, $document->getAttribute('deletion_status'));
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
        $this->assertTrue(TemporaryUpload::query()->whereKey($upload->getKey())->firstOrFail()->getAttribute('is_tombstone'));
        $this->assertStringContainsString('manuell', (string) $document->getAttribute('failure_message'));
    }

    /**
     * Ergebnis der KI-Schicht mit vorgegebenen Feldern, ohne Providerzugriff.
     *
     * @param  array<string, ExtractedValue>  $fields
     */
    private function ergebnisMitFeldern(array $fields): ExtractionResult
    {
        $metadata = new AiCallMetadata(
            'fake',
            'fake-testprovider',
            AiCallPurpose::EXTRAKTION,
            AiCallStatus::ERFOLGREICH,
            '1.0.0',
            str_repeat('a', 64),
            'rechnung_bescheid',
            '1.0.0',
            str_repeat('b', 64),
        );

        return new ExtractionResult(AiResultStatus::VALIDIERT, [], $fields, $metadata);
    }
}
