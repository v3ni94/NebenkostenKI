<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Enums\GeneratedDocumentVariant;
use App\Services\Pdf\Renderer\OwnerOverviewRenderer;
use App\Services\Pdf\Renderer\TaxBenefitAttachmentRenderer;
use App\Services\Pdf\Renderer\TenantStatementRenderer;
use App\Services\Storage\ArtifactType;
use Tests\TestCase;

/**
 * Wasserzeichenschutz der Vorschau (Abschnitt 14.3).
 *
 * Geprüft wird, dass das Wasserzeichen serverseitig in den Seiteninhalt
 * eingebrannt ist, auf jeder Seite steht und in der Finalversion vollständig
 * fehlt.
 */
class WatermarkTest extends TestCase
{
    public function test_vorschau_traegt_das_wasserzeichen_auf_jeder_seite(): void
    {
        $document = app(TenantStatementRenderer::class)->renderPreview(PdfFixtures::statementView(null, true));

        $text = config('smartabrechnen.pdf.watermark_text');
        $this->assertIsString($text);

        $this->assertSame(GeneratedDocumentVariant::VORSCHAU, $document->variant);
        $this->assertGreaterThanOrEqual(3, $document->pageCount);
        $this->assertSame(
            $document->pageCount,
            PdfTextExtractor::occurrences($document->contents, $text),
            'Das Wasserzeichen muss auf jeder Seite der Vorschau stehen.'
        );
    }

    public function test_vorschau_traegt_zusaetzlich_den_fusszeilenvermerk(): void
    {
        $document = app(TenantStatementRenderer::class)->renderPreview(PdfFixtures::statementView());

        $footer = config('smartabrechnen.pdf.watermark_footer');
        $this->assertIsString($footer);

        $this->assertSame(
            $document->pageCount,
            PdfTextExtractor::occurrences($document->contents, $footer)
        );
    }

    public function test_wasserzeichen_ist_teil_des_seiteninhalts_und_keine_entfernbare_ebene(): void
    {
        $document = app(TenantStatementRenderer::class)->renderPreview(PdfFixtures::statementView());

        $text = config('smartabrechnen.pdf.watermark_text');
        $this->assertIsString($text);

        // Der Text steht nicht im Klartext im Dateikörper, sondern
        // ausschliesslich in den komprimierten Seiteninhalten. Er ist damit
        // kein HTML-Overlay, keine Anmerkung und keine optionale Ebene.
        $this->assertStringNotContainsString($text, $document->contents);
        $this->assertGreaterThan(0, PdfTextExtractor::occurrences($document->contents, $text));
        $this->assertStringNotContainsString('/OCProperties', $document->contents);
        $this->assertStringNotContainsString('/OCGs', $document->contents);
        $this->assertStringNotContainsString('/Watermark', $document->contents);
    }

    public function test_finalversion_traegt_kein_wasserzeichen(): void
    {
        $document = app(TenantStatementRenderer::class)->renderFinal(PdfFixtures::statementView());

        $watermark = config('smartabrechnen.pdf.watermark_text');
        $footer = config('smartabrechnen.pdf.watermark_footer');
        $this->assertIsString($watermark);
        $this->assertIsString($footer);

        $text = PdfTextExtractor::text($document->contents);

        $this->assertSame(GeneratedDocumentVariant::FINAL, $document->variant);
        $this->assertStringNotContainsString($watermark, $text);
        $this->assertStringNotContainsString($footer, $text);
        $this->assertStringNotContainsString('Vorschau', $text);
    }

    public function test_vorschau_und_finalversion_werden_getrennt_gespeichert(): void
    {
        $renderer = app(TenantStatementRenderer::class);
        $view = PdfFixtures::statementView();

        $vorschau = $renderer->renderPreview($view);
        $final = $renderer->renderFinal($view);

        $this->assertSame(ArtifactType::MIETERABRECHNUNG_VORSCHAU, $vorschau->artifactType);
        $this->assertSame(ArtifactType::MIETERABRECHNUNG_FINAL, $final->artifactType);
        $this->assertNotSame($vorschau->artifactType->directory(), $final->artifactType->directory());
        $this->assertNotSame($vorschau->downloadName, $final->downloadName);
        $this->assertNotSame($vorschau->sha256(), $final->sha256());
    }

    public function test_anlage_35a_und_eigentuemeruebersicht_tragen_das_wasserzeichen_ebenfalls(): void
    {
        $watermark = config('smartabrechnen.pdf.watermark_text');
        $this->assertIsString($watermark);

        $anlage = app(TaxBenefitAttachmentRenderer::class)->renderPreview(PdfFixtures::statementView());
        $uebersicht = app(OwnerOverviewRenderer::class)->renderPreview(PdfFixtures::ownerOverviewView());

        $this->assertSame(
            $anlage->pageCount,
            PdfTextExtractor::occurrences($anlage->contents, $watermark)
        );
        $this->assertSame(
            $uebersicht->pageCount,
            PdfTextExtractor::occurrences($uebersicht->contents, $watermark)
        );
    }
}
