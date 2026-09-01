<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Services\Pdf\Renderer\AllocationKeyAttachmentRenderer;
use App\Services\Pdf\Renderer\TaxBenefitAttachmentRenderer;
use App\Services\Pdf\Renderer\VoucherIndexRenderer;
use App\Services\Storage\ArtifactType;
use Tests\TestCase;

/**
 * Anlagen: Verteilerschlüssel, § 35a EStG und optionale Belegübersicht
 * (Abschnitt 14.1, 12.4).
 */
class AttachmentPdfTest extends TestCase
{
    public function test_anlage_35a_erzeugt_ein_gueltiges_pdf(): void
    {
        $document = app(TaxBenefitAttachmentRenderer::class)->renderFinal(PdfFixtures::statementView(), null);

        $this->assertStringStartsWith('%PDF-', $document->contents);
        $this->assertSame(ArtifactType::ANLAGE_35A, $document->artifactType);
        $this->assertGreaterThanOrEqual(1, $document->pageCount);
        $this->assertSame($document->pageCount, PdfTextExtractor::pageCount($document->contents));
    }

    public function test_anlage_35a_weist_haushaltsnahe_und_handwerkerleistungen_getrennt_aus(): void
    {
        $text = PdfTextExtractor::text(
            app(TaxBenefitAttachmentRenderer::class)->renderFinal(PdfFixtures::statementView())->contents
        );

        $this->assertStringContainsString('Haushaltsnahe Dienstleistungen', $text);
        $this->assertStringContainsString('Handwerkerleistungen', $text);
        $this->assertStringContainsString('Summe begünstigter Lohnanteil, haushaltsnahe Dienstleistungen', $text);
        $this->assertStringContainsString('Summe begünstigter Lohnanteil, Handwerkerleistungen', $text);
        $this->assertStringContainsString('290,00 EUR', $text);
        $this->assertStringContainsString('95,00 EUR', $text);
    }

    public function test_anlage_35a_kennzeichnet_nicht_ausgewiesenen_lohnanteil(): void
    {
        $text = PdfTextExtractor::text(
            app(TaxBenefitAttachmentRenderer::class)->renderFinal(PdfFixtures::statementView())->contents
        );

        $this->assertStringContainsString('Lohnanteil nicht ausgewiesen', $text);
        $this->assertStringContainsString('Materialkosten sind nicht begünstigt', $text);
        $this->assertStringContainsString('keine steuerliche Beratung', $text);
    }

    public function test_anlage_35a_ohne_beguenstigte_positionen_bleibt_leer_und_erfindet_nichts(): void
    {
        $view = PdfFixtures::statementView(PdfFixtures::statementResult(PdfFixtures::manyLines(3)));

        $text = PdfTextExtractor::text(
            app(TaxBenefitAttachmentRenderer::class)->renderFinal($view)->contents
        );

        $this->assertStringContainsString('keine begünstigten haushaltsnahen Dienstleistungen nachgewiesen', $text);
        $this->assertStringContainsString('keine begünstigten Handwerkerleistungen nachgewiesen', $text);
        $this->assertStringContainsString('0,00 EUR', $text);
    }

    public function test_anlage_der_verteilerschluessel_erlaeutert_jeden_schluessel(): void
    {
        $html = app(AllocationKeyAttachmentRenderer::class)->html(PdfFixtures::statementView());

        $this->assertStringContainsString('Erläuterung der Verteilerschlüssel', $html);
        $this->assertStringContainsString('Verteilung nach Personentagen', $html);
        $this->assertStringContainsString('Zeitanteilige Berechnung', $html);
        $this->assertStringContainsString('Start- und Endtag zählen jeweils mit', $html);
    }

    public function test_belegliste_nummeriert_und_nennt_die_pflichtangaben(): void
    {
        $view = PdfFixtures::statementView(null, true);
        $html = app(VoucherIndexRenderer::class)->html($view);

        foreach (['Nummer', 'Kostenart', 'Aussteller', 'Belegdatum', 'Betrag EUR'] as $spalte) {
            $this->assertStringContainsString($spalte, $html);
        }

        $this->assertStringContainsString('Stadt Musterstadt', $html);
        $this->assertStringContainsString('15.02.2025', $html);
        $this->assertStringContainsString('1.234,56', $html);
        $this->assertStringContainsString('Originalbelege einzusehen', $html);
        $this->assertStringContainsString('vom Vermieter', $html);
    }

    public function test_belegliste_enthaelt_keine_dateipfade_und_keine_verlinkung(): void
    {
        $view = PdfFixtures::statementView(null, true);
        $html = app(VoucherIndexRenderer::class)->html($view);

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('href', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
        $this->assertStringNotContainsString('file://', $html);
        $this->assertStringNotContainsString('storage/', $html);
        $this->assertStringNotContainsString('.pdf', $html);
        $this->assertStringNotContainsString('.jpg', $html);
        $this->assertDoesNotMatchRegularExpression('#[A-Za-z0-9_-]+/[A-Za-z0-9_-]+\.[a-z]{3,4}#', $html);
    }

    public function test_belegliste_kennzeichnet_fehlende_angaben_statt_sie_zu_ergaenzen(): void
    {
        $view = PdfFixtures::statementView(null, true);
        $html = app(VoucherIndexRenderer::class)->html($view);

        $this->assertSame(3, substr_count($html, 'nicht ausgewiesen'));
    }
}
