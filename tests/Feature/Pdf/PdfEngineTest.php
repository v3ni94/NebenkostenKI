<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Services\Pdf\PdfEngine;
use App\Services\Pdf\PdfRenderOptions;
use App\Services\Storage\ArtifactType;
use Tests\TestCase;

/**
 * Renderweg mit mPDF: Seitennummerierung, Schriftgröße, Zeichensatz und
 * konservatives Layout (ADR-005, Abschnitt 3.6, 18).
 */
class PdfEngineTest extends TestCase
{
    private PdfEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(PdfEngine::class);
    }

    public function test_seitennummerierung_steht_auf_jeder_seite(): void
    {
        $html = '<p>Erste Seite</p><pagebreak /><p>Zweite Seite</p><pagebreak /><p>Dritte Seite</p>';

        $document = $this->engine->renderHtml($html, $this->renderOptions(), 'test');
        $text = PdfTextExtractor::text($document->contents);

        $this->assertSame(3, $document->pageCount);
        $this->assertStringContainsString('Seite 1 von 3', $text);
        $this->assertStringContainsString('Seite 2 von 3', $text);
        $this->assertStringContainsString('Seite 3 von 3', $text);
    }

    public function test_fliesstext_bleibt_zwischen_zehn_und_elf_punkt(): void
    {
        config()->set('smartabrechnen.pdf.body_font_pt', 10.5);
        $this->assertSame('10.5pt', $this->engine->bodyFontPt());

        config()->set('smartabrechnen.pdf.body_font_pt', 24);
        $this->assertSame('11pt', $this->engine->bodyFontPt());

        config()->set('smartabrechnen.pdf.body_font_pt', 6);
        $this->assertSame('10pt', $this->engine->bodyFontPt());

        config()->set('smartabrechnen.pdf.body_font_pt', 'unsinn');
        $this->assertSame('10.5pt', $this->engine->bodyFontPt());
    }

    public function test_templateversion_kommt_aus_der_konfiguration(): void
    {
        config()->set('smartabrechnen.pdf.template_version', '2.3.4');

        $document = $this->engine->renderHtml('<p>Test</p>', $this->renderOptions(), 'test');

        $this->assertSame('2.3.4', $document->templateVersion);
        $this->assertSame('2.3.4', $this->engine->templateVersion());
    }

    public function test_zeichensatzeinstellungen_werden_nach_dem_rendern_wiederhergestellt(): void
    {
        $vorher = mb_internal_encoding();

        $this->engine->renderHtml('<p>Müller, Größe, Straße</p>', $this->renderOptions(), 'test');

        $this->assertSame($vorher, mb_internal_encoding());
        $this->assertSame(
            'Hausverwaltung Müller GmbH',
            config('smartabrechnen.operator.legal_name'),
            'Nach dem Rendern dürfen später gelesene Werte nicht doppelt kodiert sein.'
        );
    }

    public function test_umlaute_werden_korrekt_ausgegeben(): void
    {
        $document = $this->engine->renderHtml('<p>Müller Größe Straße Düsseldorf</p>', $this->renderOptions(), 'test');

        $this->assertStringContainsString(
            'Müller Größe Straße Düsseldorf',
            PdfTextExtractor::text($document->contents)
        );
    }

    public function test_ungueltige_vorlage_fuehrt_zu_einem_fehler_und_nicht_zu_einer_datei(): void
    {
        $this->expectException(\App\Services\Pdf\PdfException::class);

        $this->engine->render('pdf.gibt-es-nicht', [], $this->renderOptions());
    }

    public function test_pdf_a_wird_nicht_behauptet(): void
    {
        $document = $this->engine->renderHtml('<p>Test</p>', $this->renderOptions(), 'test');

        $this->assertStringNotContainsString('pdfa', strtolower($document->contents));
        $this->assertStringNotContainsString('/GTS_PDFA1', $document->contents);
    }

    public function test_vorlagen_verwenden_kein_flexbox_und_kein_grid(): void
    {
        $vorlagen = glob(resource_path('views/pdf/**/*.blade.php')) ?: [];
        $vorlagen = array_merge($vorlagen, glob(resource_path('views/pdf/*.blade.php')) ?: []);

        $this->assertNotSame([], $vorlagen);

        foreach ($vorlagen as $vorlage) {
            $inhalt = (string) file_get_contents($vorlage);

            $this->assertStringNotContainsString('display: flex', $inhalt, $vorlage);
            $this->assertStringNotContainsString('display:flex', $inhalt, $vorlage);
            $this->assertStringNotContainsString('display: grid', $inhalt, $vorlage);
            $this->assertStringNotContainsString('display:grid', $inhalt, $vorlage);
            $this->assertStringNotContainsString('var(--', $inhalt, $vorlage);
        }
    }

    private function renderOptions(): PdfRenderOptions
    {
        return PdfRenderOptions::final(
            ArtifactType::EIGENTUEMERUEBERSICHT,
            'Test',
            'Erstellt über smart-abrechnen.de',
        );
    }
}
