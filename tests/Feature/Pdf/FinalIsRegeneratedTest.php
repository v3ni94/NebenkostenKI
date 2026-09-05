<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Enums\GeneratedDocumentVariant;
use App\Models\CalculationSnapshot;
use App\Models\Organization;
use App\Services\Pdf\DocumentSetFactory;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\Renderer\TenantStatementRenderer;
use App\Services\Pdf\Store\DocumentOwnership;
use App\Services\Pdf\Store\GeneratedDocumentWriter;
use App\Services\Storage\ArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Nachweis, dass die Finalversion aus demselben gesperrten Calculation
 * Snapshot vollständig NEU erzeugt wird und nicht aus der Vorschau abgeleitet
 * ist (Abschnitt 14.3).
 */
class FinalIsRegeneratedTest extends TestCase
{
    use RefreshDatabase;

    public function test_beide_wege_erzeugen_unterschiedliche_dateien_mit_gleichem_inhaltlichen_ergebnis(): void
    {
        $renderer = app(TenantStatementRenderer::class);
        $view = PdfFixtures::statementView();

        $vorschau = $renderer->renderPreview($view, 'snapshot-fix');
        $final = $renderer->renderFinal($view, 'snapshot-fix');

        $watermark = config('smartabrechnen.pdf.watermark_text');
        $this->assertIsString($watermark);

        // Gleiche fachliche Aussage, unterschiedliche Datei.
        $this->assertNotSame($vorschau->sha256(), $final->sha256());
        $this->assertSame('snapshot-fix', $vorschau->calculationSnapshotId);
        $this->assertSame('snapshot-fix', $final->calculationSnapshotId);
        $this->assertGreaterThan(0, PdfTextExtractor::occurrences($vorschau->contents, $watermark));
        $this->assertSame(0, PdfTextExtractor::occurrences($final->contents, $watermark));

        $ohneWasserzeichen = str_replace($watermark, '', PdfTextExtractor::text($vorschau->contents));
        $footer = config('smartabrechnen.pdf.watermark_footer');
        $this->assertIsString($footer);
        $ohneWasserzeichen = str_replace(' | '.$footer, '', $ohneWasserzeichen);

        $this->assertSame(
            $this->normalizeText($ohneWasserzeichen),
            $this->normalizeText(PdfTextExtractor::text($final->contents)),
            'Vorschau und Finalversion müssen denselben Abrechnungsinhalt zeigen.'
        );
    }

    public function test_finalversion_wird_erneut_erzeugt_und_ist_reproduzierbar(): void
    {
        $renderer = app(TenantStatementRenderer::class);
        $view = PdfFixtures::statementView();

        $erste = $renderer->renderFinal($view, 'snapshot-fix');
        $zweite = $renderer->renderFinal($view, 'snapshot-fix');

        $this->assertSame(
            $this->normalizeBytes($erste->contents),
            $this->normalizeBytes($zweite->contents),
            'Aus demselben Snapshot muss dieselbe Finalversion entstehen.'
        );
    }

    public function test_finalversion_entsteht_auch_ohne_jede_vorschau(): void
    {
        Storage::fake('local');

        $view = PdfFixtures::statementView();
        $writer = app(GeneratedDocumentWriter::class);
        $ownership = new DocumentOwnership($this->organizationId());

        // Es wurde nie eine Vorschau erzeugt oder gespeichert.
        $final = app(TenantStatementRenderer::class)->renderFinal($view, $this->snapshotId());
        $gespeichert = $writer->store($final, $ownership);

        $this->assertTrue(app(ArtifactStorage::class)->exists($gespeichert->artifact));
        $this->assertSame(GeneratedDocumentVariant::FINAL, $gespeichert->record->variant);
        $this->assertTrue($final->isPdf());
    }

    public function test_geloeschte_vorschau_verhindert_die_finalversion_nicht(): void
    {
        Storage::fake('local');

        $view = PdfFixtures::statementView();
        $writer = app(GeneratedDocumentWriter::class);
        $storage = app(ArtifactStorage::class);
        $ownership = new DocumentOwnership($this->organizationId());

        $snapshotId = $this->snapshotId();

        $vorschau = app(TenantStatementRenderer::class)->renderPreview($view, $snapshotId);
        $gespeicherteVorschau = $writer->store($vorschau, $ownership);

        $storage->delete($gespeicherteVorschau->artifact);
        $this->assertFalse($storage->exists($gespeicherteVorschau->artifact));

        $final = app(TenantStatementRenderer::class)->renderFinal($view, $snapshotId);
        $gespeicherteFinal = $writer->store($final, $ownership);

        $this->assertTrue($storage->exists($gespeicherteFinal->artifact));
        $this->assertNotSame($gespeicherteVorschau->artifact->path, $gespeicherteFinal->artifact->path);
    }

    public function test_kein_renderweg_nimmt_ein_bestehendes_pdf_entgegen(): void
    {
        foreach ([TenantStatementRenderer::class, DocumentSetFactory::class] as $klasse) {
            $reflection = new ReflectionClass($klasse);

            foreach ($reflection->getMethods() as $method) {
                $this->assertStringNotContainsStringIgnoringCase('watermark', $method->getName());

                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();

                    if ($type instanceof ReflectionNamedType && $type->getName() === PdfDocument::class) {
                        $this->fail(sprintf(
                            'Die Finalversion darf nicht aus einem bestehenden PDF abgeleitet werden: %s::%s',
                            $klasse,
                            $method->getName()
                        ));
                    }
                }
            }
        }
    }

    public function test_dokumentensatz_erzeugt_vorschau_und_finalversion_getrennt(): void
    {
        $factory = app(DocumentSetFactory::class);
        $views = [PdfFixtures::statementView()];

        $vorschau = $factory->previewSet($views, PdfFixtures::ownerOverviewView(), 'snapshot-fix');
        $final = $factory->finalSet($views, PdfFixtures::ownerOverviewView(), 'snapshot-fix');

        $this->assertSame(GeneratedDocumentVariant::VORSCHAU, $vorschau->variant);
        $this->assertSame(GeneratedDocumentVariant::FINAL, $final->variant);
        $this->assertSame(3, $vorschau->count());
        $this->assertSame(3, $final->count());
        $this->assertGreaterThan(0, $vorschau->totalPages());

        foreach ($vorschau->all() as $document) {
            $this->assertSame(GeneratedDocumentVariant::VORSCHAU, $document->variant);
        }

        foreach ($final->all() as $document) {
            $this->assertSame(GeneratedDocumentVariant::FINAL, $document->variant);
        }
    }

    private function organizationId(): string
    {
        return Organization::factory()->create()->id;
    }

    private function snapshotId(): string
    {
        return CalculationSnapshot::factory()->create()->id;
    }

    /**
     * Entfernt Erzeugungszeitpunkte und Dokumentkennungen, damit zwei
     * Renderläufe vergleichbar sind.
     */
    private function normalizeBytes(string $pdf): string
    {
        $normalized = preg_replace('/\/(CreationDate|ModDate) \([^)]*\)/', '/$1 (X)', $pdf);
        $normalized = is_string($normalized) ? $normalized : $pdf;

        $normalized = preg_replace('/<xmp:(CreateDate|ModifyDate|MetadataDate)>[^<]*</', '<xmp:$1>X<', $normalized);
        $normalized = is_string($normalized) ? $normalized : $pdf;

        // Die Dokumentkennung /ID leitet mPDF aus dem Erzeugungszeitpunkt ab.
        $normalized = preg_replace('/\/ID \[<[0-9a-f]+> <[0-9a-f]+>\]/', '/ID [<X> <X>]', $normalized);

        return is_string($normalized) ? $normalized : $pdf;
    }

    private function normalizeText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', $text);

        return trim(is_string($normalized) ? $normalized : $text);
    }
}
