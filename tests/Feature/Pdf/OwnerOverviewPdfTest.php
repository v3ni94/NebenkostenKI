<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Services\Pdf\Renderer\OwnerOverviewRenderer;
use App\Services\Storage\ArtifactType;
use Tests\TestCase;

/**
 * Eigentümerübersicht als internes Blatt je Lauf (Abschnitt 14.2).
 */
class OwnerOverviewPdfTest extends TestCase
{
    private OwnerOverviewRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = app(OwnerOverviewRenderer::class);
    }

    public function test_eigentuemeruebersicht_erzeugt_ein_gueltiges_pdf(): void
    {
        $document = $this->renderer->renderFinal(PdfFixtures::ownerOverviewView());

        $this->assertStringStartsWith('%PDF-', $document->contents);
        $this->assertSame(ArtifactType::EIGENTUEMERUEBERSICHT, $document->artifactType);
        $this->assertGreaterThanOrEqual(1, $document->pageCount);
        $this->assertSame($document->pageCount, PdfTextExtractor::pageCount($document->contents));
        $this->assertSame('eigentuemeruebersicht-2025-wohnanlage-rosenstrasse-12.pdf', $document->downloadName);
    }

    public function test_alle_einheiten_und_mietverhaeltnisse_mit_ergebnis_werden_ausgewiesen(): void
    {
        $text = PdfTextExtractor::text($this->renderer->renderFinal(PdfFixtures::ownerOverviewView())->contents);

        $this->assertStringContainsString('Einheiten und Mietverhältnisse', $text);
        $this->assertStringContainsString('Beispielmieterin', $text);
        $this->assertStringContainsString('Herr Beispielmieter', $text);
        $this->assertStringContainsString('Wohnung 3', $text);
        $this->assertStringContainsString('Wohnung 4', $text);
        $this->assertStringContainsString('Nachzahlung Mieter', $text);
        $this->assertStringContainsString('Guthaben Mieter', $text);
    }

    public function test_leerstandsanteile_zulasten_eigentuemer_werden_ausgewiesen(): void
    {
        $view = PdfFixtures::ownerOverviewView();
        $text = PdfTextExtractor::text($this->renderer->renderFinal($view)->contents);

        $this->assertStringContainsString('Leerstandsanteile zulasten Eigentümer', $text);
        $this->assertStringContainsString('Wohnung 5', $text);
        $this->assertStringContainsString('01.07.2025 bis 30.09.2025', $text);
        $this->assertStringContainsString('92', $text);
        $this->assertStringContainsString($view->result->vacancyTotal->formatAmount(), $text);
    }

    public function test_ausgeschlossene_kosten_werden_mit_grund_ausgewiesen(): void
    {
        $text = PdfTextExtractor::text($this->renderer->renderFinal(PdfFixtures::ownerOverviewView())->contents);

        $this->assertStringContainsString('Ausgeschlossene Kosten', $text);
        $this->assertStringContainsString('Verwaltungskosten', $text);
        $this->assertStringContainsString('Instandhaltungsrücklage', $text);
        $this->assertStringContainsString('nicht umlagefähig', $text);
        $this->assertStringContainsString('Summe ausgeschlossene Kosten', $text);
    }

    public function test_restanteile_pruefwarnungen_und_manuelle_entscheidungen_werden_ausgewiesen(): void
    {
        $text = PdfTextExtractor::text($this->renderer->renderFinal(PdfFixtures::ownerOverviewView())->contents);

        $this->assertStringContainsString('Nicht verteilte Restanteile', $text);
        $this->assertStringContainsString('Prüfwarnungen', $text);
        $this->assertStringContainsString('DOM.PREVIOUS_YEAR_DEVIATION', $text);
        $this->assertStringContainsString('Warnung', $text);
        $this->assertStringContainsString('Manuelle Entscheidungen', $text);
        $this->assertStringContainsString('Position wurde einbezogen', $text);
        $this->assertStringContainsString('20.03.2026', $text);
    }

    public function test_gesamtsummen_und_pruefsummen_werden_ausgewiesen(): void
    {
        $view = PdfFixtures::ownerOverviewView();
        $text = PdfTextExtractor::text($this->renderer->renderFinal($view)->contents);

        $this->assertStringContainsString('Gesamtsummen und Prüfsummen', $text);
        $this->assertStringContainsString('Vom Eigentümer zu tragen', $text);
        $this->assertStringContainsString($view->result->ownerBurdenTotal()->format(), $text);
        $this->assertStringContainsString('Prüfsumme Verteilung', $text);
        $this->assertStringContainsString('ausgeglichen', $text);
        $this->assertTrue($view->result->isBalanced());
    }

    public function test_dokumentenuebersicht_nennt_pruefsumme_und_seitenzahl_ohne_ablagepfad(): void
    {
        $view = PdfFixtures::ownerOverviewView();
        $html = $this->renderer->html($view);
        $text = PdfTextExtractor::text($this->renderer->renderFinal($view)->contents);

        $this->assertStringContainsString('Dokumentenübersicht', $text);
        $this->assertStringContainsString('Finalversion', $text);
        $this->assertStringContainsString(str_repeat('a', 16), $text);
        $this->assertStringNotContainsString('storage_path', $html);
        $this->assertStringNotContainsString('abrechnungen/final', $html);
        $this->assertStringNotContainsString('href', $html);
    }

    public function test_uebersicht_ist_als_internes_blatt_gekennzeichnet(): void
    {
        $text = PdfTextExtractor::text($this->renderer->renderFinal(PdfFixtures::ownerOverviewView())->contents);

        $this->assertStringContainsString('Internes Übersichtsblatt', $text);
        $this->assertStringContainsString('nicht zum Versand an Mieter bestimmt', $text);
        $this->assertStringContainsString('Lauf 2025-0001', $text);
    }
}
