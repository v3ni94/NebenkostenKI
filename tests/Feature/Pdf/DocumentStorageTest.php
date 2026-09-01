<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Models\BillingRun;
use App\Models\CalculationSnapshot;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Services\Pdf\DocumentSetFactory;
use App\Services\Pdf\Renderer\OperatorInvoiceRenderer;
use App\Services\Pdf\Renderer\TenantStatementRenderer;
use App\Services\Pdf\Store\DocumentOwnership;
use App\Services\Pdf\Store\DocumentPackageBuilder;
use App\Services\Pdf\Store\GeneratedDocumentWriter;
use App\Services\Storage\ArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Dauerhafte Ablage und Sammeldownload (Abschnitt 3.6).
 *
 * Die Tests verwenden ausschliesslich die lokale Testdisk, niemals einen
 * echten SFTP-Server.
 */
class DocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_nachweisangaben_werden_je_datei_gespeichert(): void
    {
        $organization = Organization::factory()->create();
        $snapshot = CalculationSnapshot::factory()->create();

        $document = app(TenantStatementRenderer::class)->renderFinal(PdfFixtures::statementView(), $snapshot->id);
        $gespeichert = app(GeneratedDocumentWriter::class)->store(
            $document,
            new DocumentOwnership($organization->id)
        );

        $record = $gespeichert->record->refresh();

        $this->assertSame($document->sha256(), $record->sha256);
        $this->assertSame($document->byteSize(), $record->byte_size);
        $this->assertSame($document->pageCount, $record->page_count);
        $this->assertSame($document->templateVersion, $record->template_version);
        $this->assertSame($snapshot->id, $record->calculation_snapshot_id);
        $this->assertSame(GeneratedDocumentKind::MIETERABRECHNUNG, $record->kind);
        $this->assertSame(GeneratedDocumentVariant::FINAL, $record->variant);
        $this->assertSame(GeneratedDocumentStatus::AKTIV, $record->status);
        $this->assertSame('local', $record->storage_disk);
        $this->assertNotNull($record->generated_at);
    }

    public function test_datei_liegt_mandantengetrennt_in_der_artefaktablage(): void
    {
        $organization = Organization::factory()->create();

        $document = app(TenantStatementRenderer::class)->renderFinal(PdfFixtures::statementView());
        $gespeichert = app(GeneratedDocumentWriter::class)->store(
            $document,
            new DocumentOwnership($organization->id)
        );

        $storage = app(ArtifactStorage::class);

        $this->assertTrue($storage->exists($gespeichert->artifact));
        $this->assertStringStartsWith('abrechnungen/final/', $gespeichert->artifact->path);
        $this->assertStringContainsString($organization->id, $gespeichert->artifact->path);
        $this->assertStringStartsWith('%PDF-', (string) $storage->get($gespeichert->artifact));
        $this->assertSame($document->byteSize(), $storage->size($gespeichert->artifact));
    }

    public function test_vorschau_und_finalversion_liegen_in_getrennten_verzeichnissen(): void
    {
        $organization = Organization::factory()->create();
        $writer = app(GeneratedDocumentWriter::class);
        $ownership = new DocumentOwnership($organization->id);
        $view = PdfFixtures::statementView();

        $vorschau = $writer->store(app(TenantStatementRenderer::class)->renderPreview($view), $ownership);
        $final = $writer->store(app(TenantStatementRenderer::class)->renderFinal($view), $ownership);

        $this->assertStringStartsWith('abrechnungen/vorschau/', $vorschau->artifact->path);
        $this->assertStringStartsWith('abrechnungen/final/', $final->artifact->path);
        $this->assertNotSame($vorschau->record->sha256, $final->record->sha256);
    }

    public function test_ersetzte_version_bleibt_erhalten_und_wird_verkettet(): void
    {
        $organization = Organization::factory()->create();
        $writer = app(GeneratedDocumentWriter::class);
        $ownership = new DocumentOwnership($organization->id);
        $view = PdfFixtures::statementView();

        $erste = $writer->store(app(TenantStatementRenderer::class)->renderFinal($view), $ownership);
        $zweite = $writer->store(app(TenantStatementRenderer::class)->renderFinal($view), $ownership);

        $writer->markReplaced($erste->record, $zweite->record);

        $this->assertSame(GeneratedDocumentStatus::ERSETZT, $erste->record->refresh()->status);
        $this->assertSame($zweite->record->id, $erste->record->refresh()->replaced_by_document_id);
        $this->assertTrue(app(ArtifactStorage::class)->exists($erste->artifact));
        $this->assertSame(2, GeneratedDocument::query()->count());
    }

    public function test_dokumente_eines_laufs_werden_dem_lauf_zugeordnet(): void
    {
        $organization = Organization::factory()->create();
        $run = BillingRun::factory()->create();

        $document = app(OperatorInvoiceRenderer::class)->render(PdfFixtures::invoiceView());
        $gespeichert = app(GeneratedDocumentWriter::class)->store(
            $document,
            new DocumentOwnership($organization->id, $run->id)
        );

        $this->assertSame($run->id, $gespeichert->record->billing_run_id);
        $this->assertSame(GeneratedDocumentKind::HVM_RECHNUNG, $gespeichert->record->kind);
        $this->assertStringStartsWith('rechnungen/', $gespeichert->artifact->path);
    }

    public function test_zip_paket_enthaelt_alle_einzeldateien(): void
    {
        $satz = app(DocumentSetFactory::class)->finalSet(
            [PdfFixtures::statementView(), PdfFixtures::statementView()],
            PdfFixtures::ownerOverviewView(),
        );

        $paket = app(DocumentPackageBuilder::class)->build($satz->all());

        $this->assertStringStartsWith("PK\x03\x04", $paket['contents']);
        $this->assertCount($satz->count(), $paket['entries']);

        $pfad = tempnam(sys_get_temp_dir(), 'sa-test-');
        $this->assertIsString($pfad);
        file_put_contents($pfad, $paket['contents']);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($pfad) === true);
        $this->assertSame($satz->count(), $zip->numFiles);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $this->assertIsString($name);
            $this->assertStringEndsWith('.pdf', $name);
            $this->assertStringStartsWith('%PDF-', (string) $zip->getFromIndex($i));
        }

        $zip->close();
        @unlink($pfad);
    }

    public function test_zip_paket_mischt_vorschau_und_finalversion_nicht(): void
    {
        $view = PdfFixtures::statementView();

        $this->expectException(RuntimeException::class);

        app(DocumentPackageBuilder::class)->build([
            app(TenantStatementRenderer::class)->renderPreview($view),
            app(TenantStatementRenderer::class)->renderFinal($view),
        ]);
    }

    public function test_leeres_zip_paket_wird_nicht_erzeugt(): void
    {
        $this->expectException(RuntimeException::class);

        app(DocumentPackageBuilder::class)->build([]);
    }

    public function test_zip_paket_wird_als_artefakt_gespeichert(): void
    {
        $organization = Organization::factory()->create();
        $builder = app(DocumentPackageBuilder::class);

        $paket = $builder->build([app(TenantStatementRenderer::class)->renderFinal(PdfFixtures::statementView())]);

        $referenz = app(ArtifactStorage::class)->put(
            $builder->artifactType(),
            $organization->id,
            $paket['contents']
        );

        $this->assertStringStartsWith('pakete/', $referenz->path);
        $this->assertSame(hash('sha256', $paket['contents']), $referenz->sha256);
        $this->assertTrue(app(ArtifactStorage::class)->exists($referenz));
    }
}
