<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AllocationKeyType;
use App\Enums\ApportionmentStatus;
use App\Enums\BillingRunStatus;
use App\Enums\CostItemStatus;
use App\Enums\DeletionStatus;
use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Models\CostCategory;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\Landlord;
use App\Models\MeterReading;
use App\Models\Property;
use App\Models\TemporaryUpload;
use App\Models\Unit;
use App\Models\UnitStatement;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prueft das Datenmodell strukturell und fachlich.
 *
 * Die Tests laufen bewusst auf einer SQLite-Verbindung im Speicher, damit kein
 * Datenbankserver erforderlich ist. Die Migrationen sind so geschrieben, dass sie
 * sowohl auf SQLite als auch auf MariaDB 10.11 und 11.x laufen.
 */
class DataModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Spalten, die in "documents" niemals vorkommen duerfen.
     *
     * Originaldateien werden nicht dauerhaft gespeichert. Es gibt daher weder einen
     * Originaldateinamen noch eine dauerhafte Storage-Referenz, keinen OCR-Volltext
     * und keinen Vorschauschluessel.
     *
     * @var list<string>
     */
    private const FORBIDDEN_DOCUMENT_COLUMNS = [
        'original_filename',
        'original_name',
        'filename',
        'file_name',
        'client_name',
        'storage_path',
        'storage_key',
        'storage_disk',
        'file_path',
        'path',
        'disk',
        'url',
        'preview_path',
        'preview_key',
        'thumbnail_path',
        'ocr_text',
        'full_text',
        'text_layer',
        'raw_text',
        'exif',
        'content',
        'contents',
        'file',
        'blob',
        'provider_file_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Die Testverbindung wird ausdruecklich hier gesetzt, damit die Testsuite
        // unabhaengig von phpunit.xml und ohne Datenbankserver laeuft.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);

        // Fuer die verschluesselten Attribute wird ein Testschluessel benoetigt.
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_alle_migrationen_erzeugen_die_erwarteten_tabellen(): void
    {
        $expected = [
            'users', 'organizations', 'organization_user', 'admin_roles', 'legal_acceptances',
            'landlords', 'properties', 'units',
            'tenancies', 'tenancy_persons', 'occupancy_periods', 'vacancy_periods',
            'meter_devices', 'meter_readings',
            'cost_categories',
            'billing_runs', 'billing_run_versions', 'calculation_snapshots',
            'documents', 'temporary_uploads', 'document_pages', 'extracted_fields',
            'document_relations', 'source_deletion_events',
            'ai_prompt_versions', 'ai_calls', 'processing_jobs',
            'cost_items', 'allocation_keys', 'allocation_key_values', 'prepayments',
            'heating_statements', 'heating_statement_lines',
            'unit_statements', 'unit_statement_lines', 'validation_issues', 'manual_overrides',
            'payments', 'webhook_events', 'invoices', 'invoice_items',
            'email_messages', 'email_suppressions', 'reminder_preferences', 'reminder_events',
            'audit_logs', 'generated_documents',
        ];

        foreach ($expected as $table) {
            $this->assertTrue(Schema::hasTable($table), "Tabelle {$table} fehlt.");
        }
    }

    public function test_jede_migration_ist_rollbackfaehig(): void
    {
        $files = glob(database_path('migrations/*.php'));
        $this->assertNotFalse($files);
        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            $migration = require $file;
            $this->assertTrue(
                method_exists($migration, 'down'),
                'Migration ohne down(): '.basename($file)
            );
        }
    }

    public function test_migrationen_verwenden_keine_gleitkommaspalten(): void
    {
        foreach ($this->migrationSources() as $file => $source) {
            foreach (['->float(', '->double(', '->real(', 'unsignedFloat', 'unsignedDouble'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    'Gleitkommaspalte in '.$file
                );
            }
        }
    }

    public function test_alle_geldspalten_enden_auf_cent(): void
    {
        foreach ($this->migrationSources() as $file => $source) {
            preg_match_all("/->bigInteger\('([a-z0-9_]+)'\)/", $source, $matches);

            foreach ($matches[1] as $column) {
                $this->assertStringEndsWith(
                    '_cent',
                    $column,
                    "Spalte {$column} in {$file} ist BIGINT, endet aber nicht auf _cent."
                );
            }
        }
    }

    public function test_documents_enthaelt_keine_referenz_auf_die_originaldatei(): void
    {
        $columns = Schema::getColumnListing('documents');

        foreach (self::FORBIDDEN_DOCUMENT_COLUMNS as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                "Verbotene Spalte documents.{$forbidden} gefunden."
            );
        }

        // Erlaubte Nachweisspalten muessen vorhanden sein.
        foreach (['source_label', 'fingerprint_hmac', 'original_deleted_at', 'deletion_status', 'page_count'] as $required) {
            $this->assertContains($required, $columns, "Pflichtspalte documents.{$required} fehlt.");
        }
    }

    public function test_temporary_uploads_ist_der_einzige_ort_mit_storage_key(): void
    {
        $this->assertContains('storage_key', Schema::getColumnListing('temporary_uploads'));
        $this->assertContains('expires_at', Schema::getColumnListing('temporary_uploads'));
        $this->assertContains('deletion_attempts', Schema::getColumnListing('temporary_uploads'));
        $this->assertContains('last_error', Schema::getColumnListing('temporary_uploads'));

        // Keine weitere Kundendatentabelle darf einen Storage-Key auf ein Original tragen.
        foreach (['documents', 'document_pages', 'extracted_fields', 'ai_calls'] as $table) {
            $columns = Schema::getColumnListing($table);
            $this->assertNotContains('storage_key', $columns, "Storage-Key in {$table} gefunden.");
            $this->assertNotContains('storage_path', $columns, "Storage-Pfad in {$table} gefunden.");
        }
    }

    public function test_document_pages_und_extracted_fields_speichern_keinen_volltext(): void
    {
        $pageColumns = Schema::getColumnListing('document_pages');
        foreach (['ocr_text', 'text', 'full_text', 'preview_key', 'image_path'] as $forbidden) {
            $this->assertNotContains($forbidden, $pageColumns);
        }

        $fieldColumns = Schema::getColumnListing('extracted_fields');
        $this->assertContains('source_excerpt', $fieldColumns);
        foreach (['ocr_text', 'full_text', 'raw_response'] as $forbidden) {
            $this->assertNotContains($forbidden, $fieldColumns);
        }
    }

    public function test_ai_calls_speichert_keine_rohen_prompts_oder_antworten(): void
    {
        $columns = Schema::getColumnListing('ai_calls');

        foreach (['prompt', 'raw_prompt', 'response', 'raw_response', 'payload', 'messages', 'input', 'output'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "Verbotene Spalte ai_calls.{$forbidden} gefunden.");
        }

        foreach (['provider', 'model', 'purpose', 'request_id', 'input_tokens', 'output_tokens', 'cost_cent', 'status'] as $required) {
            $this->assertContains($required, $columns);
        }
    }

    public function test_primaerschluessel_sind_ulids(): void
    {
        $property = Property::factory()->create();
        $unit = Unit::factory()->for($property)->create();

        $this->assertTrue(Str::isUlid((string) $property->getKey()));
        $this->assertTrue(Str::isUlid((string) $unit->getKey()));
        $this->assertSame('string', $property->getKeyType());
        $this->assertFalse($property->getIncrementing());
    }

    public function test_geldbetraege_werden_als_integer_gespeichert_und_gelesen(): void
    {
        $costItem = CostItem::factory()->create([
            'amount_cent' => 128450,
            'labor_share_cent' => 41200,
        ]);

        $fresh = $costItem->fresh();
        $this->assertNotNull($fresh);
        $this->assertIsInt($fresh->amount_cent);
        $this->assertSame(128450, $fresh->amount_cent);
        $this->assertIsInt($fresh->labor_share_cent);

        $raw = DB::table('cost_items')->where('id', $costItem->getKey())->value('amount_cent');
        $this->assertSame(128450, (int) $raw);

        // Negative Betraege, zum Beispiel Gutschriften, bleiben exakt.
        $credit = CostItem::factory()->create(['amount_cent' => -4599]);
        $freshCredit = $credit->fresh();
        $this->assertNotNull($freshCredit);
        $this->assertSame(-4599, $freshCredit->amount_cent);
    }

    public function test_dezimalwerte_werden_nicht_durch_gleitkomma_verfaelscht(): void
    {
        $unit = Unit::factory()->create([
            'living_area_sqm' => '72.5500',
            'heated_area_sqm' => '70.2500',
            'mea' => '87.123456',
            'individual_key_1_value' => '1234.5678',
        ]);

        $fresh = $unit->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('72.5500', $fresh->living_area_sqm);
        $this->assertSame('70.2500', $fresh->heated_area_sqm);
        $this->assertSame('87.123456', $fresh->mea);
        $this->assertSame('1234.5678', $fresh->individual_key_1_value);

        $reading = MeterReading::factory()->create(['value' => '9876.5432']);
        $freshReading = $reading->fresh();
        $this->assertNotNull($freshReading);
        $this->assertSame('9876.5432', $freshReading->value);

        $statement = UnitStatement::factory()->create();
        $line = $statement->lines()->create([
            'organization_id' => $statement->organization_id,
            'category_label' => 'Wasserversorgung',
            'total_cost_cent' => 100000,
            'allocation_key_type' => AllocationKeyType::VERBRAUCH,
            'allocation_key_label' => 'Verbrauch in Kubikmetern',
            'numerator' => '12.345678',
            'denominator' => '987.654321',
            'time_factor' => '0.49863014',
            'share_cent' => 623,
        ]);

        $freshLine = $line->fresh();
        $this->assertNotNull($freshLine);
        $this->assertSame('12.345678', $freshLine->numerator);
        $this->assertSame('987.654321', $freshLine->denominator);
        $this->assertSame('0.49863014', $freshLine->time_factor);
    }

    public function test_enums_casten_korrekt(): void
    {
        $run = BillingRun::factory()->create(['status' => BillingRunStatus::REVIEW_REQUIRED]);
        $fresh = $run->fresh();
        $this->assertNotNull($fresh);

        $this->assertInstanceOf(BillingRunStatus::class, $fresh->status);
        $this->assertSame(BillingRunStatus::REVIEW_REQUIRED, $fresh->status);
        $this->assertSame('Bitte prüfen', $fresh->status->label());
        $this->assertSame(
            'REVIEW_REQUIRED',
            DB::table('billing_runs')->where('id', $run->getKey())->value('status')
        );

        $document = Document::factory()->create([
            'document_type' => DocumentType::HEIZKOSTENABRECHNUNG,
            'deletion_status' => DeletionStatus::ERFOLGREICH,
        ]);
        $freshDocument = $document->fresh();
        $this->assertNotNull($freshDocument);
        $this->assertSame(DocumentType::HEIZKOSTENABRECHNUNG, $freshDocument->document_type);
        $this->assertSame(DeletionStatus::ERFOLGREICH, $freshDocument->deletion_status);
        $this->assertFalse($freshDocument->deletion_status->isPrivacyAlert());

        $item = CostItem::factory()->create(['status' => CostItemStatus::BESTAETIGT]);
        $freshItem = $item->fresh();
        $this->assertNotNull($freshItem);
        $this->assertSame(CostItemStatus::BESTAETIGT, $freshItem->status);
    }

    public function test_verschluesselte_bankdaten_sind_in_der_datenbank_nicht_im_klartext(): void
    {
        $landlord = Landlord::factory()->create(['iban' => 'DE99999999999999999999']);

        $raw = DB::table('landlords')->where('id', $landlord->getKey())->value('iban');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('DE99999999999999999999', $raw);

        $fresh = $landlord->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('DE99999999999999999999', $fresh->iban);
    }

    public function test_temporaerer_upload_kann_auf_einen_tombstone_reduziert_werden(): void
    {
        $upload = TemporaryUpload::factory()->tombstone()->create();

        $fresh = $upload->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->storage_key);
        $this->assertTrue($fresh->is_tombstone);
        $this->assertSame(0, TemporaryUpload::query()->pendingDeletion()->count());
    }

    public function test_kostenkategorien_seeder_legt_die_standardkategorien_an(): void
    {
        $this->seed(CostCategorySeeder::class);

        $this->assertGreaterThanOrEqual(28, CostCategory::query()->count());

        $grundsteuer = CostCategory::query()->where('code', 'GRUNDSTEUER')->firstOrFail();
        $this->assertSame(ApportionmentStatus::UMLAGEFAEHIG, $grundsteuer->apportionment_status);
        $this->assertSame(AllocationKeyType::WOHNFLAECHE, $grundsteuer->default_allocation_key_type);
        $this->assertNotNull($grundsteuer->betrkv_reference);
        $this->assertNull($grundsteuer->valid_to);

        // Der date-Cast liefert einen Carbon-Wert, der Gueltigkeitsbeginn ist der
        // 01.01.2004, das Inkrafttreten der Betriebskostenverordnung.
        $validFrom = $grundsteuer->getAttribute('valid_from');
        $this->assertInstanceOf(Carbon::class, $validFrom);
        $this->assertSame('2004-01-01', $validFrom->format('Y-m-d'));

        foreach ([
            'VERWALTUNGSKOSTEN',
            'INSTANDHALTUNG_INSTANDSETZUNG',
            'REPARATUREN',
            'BANK_FINANZIERUNGSKOSTEN',
            'RECHTSKOSTEN',
            'RUECKLAGENZUFUEHRUNG',
        ] as $code) {
            $category = CostCategory::query()->where('code', $code)->firstOrFail();
            $this->assertSame(
                ApportionmentStatus::NICHT_UMLAGEFAEHIG,
                $category->apportionment_status,
                "Kategorie {$code} muss nicht umlagefaehig sein."
            );
            $this->assertTrue($category->excluded_from_apportionment_by_default);
        }

        $modernisierung = CostCategory::query()->where('code', 'NEUANSCHAFFUNG_MODERNISIERUNG')->firstOrFail();
        $this->assertSame(ApportionmentStatus::PRUEFPFLICHTIG, $modernisierung->apportionment_status);
        $this->assertTrue($modernisierung->excluded_from_apportionment_by_default);

        $sonstige = CostCategory::query()->where('code', 'SONSTIGE_BETRIEBSKOSTEN')->firstOrFail();
        $this->assertTrue($sonstige->requires_contract_basis);
        $this->assertNotNull($sonstige->warning_note);
    }

    public function test_kostenkategorien_seeder_ist_idempotent(): void
    {
        $this->seed(CostCategorySeeder::class);
        $first = CostCategory::query()->count();

        $this->seed(CostCategorySeeder::class);

        $this->assertSame($first, CostCategory::query()->count());
    }

    public function test_beziehungen_der_aggregatwurzeln_funktionieren(): void
    {
        $run = BillingRun::factory()->create();
        $document = Document::factory()->for($run)->create([
            'organization_id' => $run->organization_id,
        ]);
        CostItem::factory()->for($run)->create([
            'organization_id' => $run->organization_id,
            'document_id' => $document->getKey(),
        ]);

        $this->assertSame(1, $run->documents()->count());
        $this->assertSame(1, $run->costItems()->count());
        $this->assertSame(1, $document->costItems()->count());
        $this->assertTrue($document->billingRun->is($run));
    }

    public function test_jedes_modell_besitzt_eine_funktionsfaehige_factory(): void
    {
        $files = glob(database_path('factories/*Factory.php'));
        $this->assertNotFalse($files);
        $this->assertGreaterThanOrEqual(40, count($files));

        foreach ($files as $file) {
            $modelClass = 'App\\Models\\'.Str::beforeLast(basename($file, '.php'), 'Factory');

            $this->assertTrue(class_exists($modelClass), "Modell {$modelClass} fehlt.");

            /** @var Model $model */
            $model = new $modelClass;
            $created = $modelClass::factory()->create();

            $this->assertTrue(Str::isUlid((string) $created->getKey()), $modelClass.' ohne ULID');
            $this->assertDatabaseHas($model->getTable(), ['id' => $created->getKey()]);
        }
    }

    /**
     * Quelltexte aller Migrationen.
     *
     * @return array<string, string>
     */
    private function migrationSources(): array
    {
        $files = glob(database_path('migrations/*.php'));
        $this->assertNotFalse($files);

        $sources = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertNotFalse($content);
            $sources[basename($file)] = $content;
        }

        return $sources;
    }
}
