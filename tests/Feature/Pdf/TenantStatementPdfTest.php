<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Money\Money;
use App\Services\Pdf\Renderer\TenantStatementRenderer;
use App\Services\Pdf\Support\HvmCorporateIdentity;
use App\Services\Storage\ArtifactType;
use Tests\TestCase;

/**
 * Mieterabrechnung: Pflichtinhalte, Neutralität und Seitenumbruch
 * (Abschnitt 14.1, 11.1, 2.2).
 */
class TenantStatementPdfTest extends TestCase
{
    private TenantStatementRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = app(TenantStatementRenderer::class);
    }

    public function test_mieterabrechnung_erzeugt_ein_gueltiges_pdf(): void
    {
        $document = $this->renderer->renderFinal(PdfFixtures::statementView(), 'snapshot-1');

        $this->assertStringStartsWith('%PDF-', $document->contents);
        $this->assertTrue($document->isPdf());
        $this->assertGreaterThanOrEqual(2, $document->pageCount);
        $this->assertSame($document->pageCount, PdfTextExtractor::pageCount($document->contents));
        $this->assertSame(ArtifactType::MIETERABRECHNUNG_FINAL, $document->artifactType);
        $this->assertSame('snapshot-1', $document->calculationSnapshotId);
        $this->assertSame(config('smartabrechnen.pdf.template_version'), $document->templateVersion);
    }

    public function test_alle_pflichtangaben_der_mieterabrechnung_sind_enthalten(): void
    {
        $document = $this->renderer->renderFinal(PdfFixtures::statementView());
        $text = PdfTextExtractor::text($document->contents);

        // Absender Vermieter, Empfänger mit Zustellanschrift
        $this->assertStringContainsString('Beispiel Vermietung Sonnenweg', $text);
        $this->assertStringContainsString('Frau Beispielmieterin', $text);
        $this->assertStringContainsString('Rosenstraße 12', $text);
        $this->assertStringContainsString('40764 Musterstadt', $text);

        // Informationsblock und Betreff
        $this->assertStringContainsString('Betriebskostenabrechnung 01.01.2025 bis 31.12.2025', $text);
        $this->assertStringContainsString('Wohnanlage Rosenstraße 12', $text);
        $this->assertStringContainsString('Wohnung 3', $text);
        $this->assertStringContainsString('Abrechnungszeitraum', $text);
        $this->assertStringContainsString('Nutzungszeitraum', $text);
        $this->assertStringContainsString('31.03.2026', $text);

        // Spalten der Kostentabelle
        foreach (['Kostenart', 'Gesamtkosten EUR', 'Verteilerschlüssel', 'Ihr Anteil', 'Betrag EUR'] as $spalte) {
            $this->assertStringContainsString($spalte, $text);
        }

        // Rechenweg je Zeile
        $this->assertStringContainsString('72,50 m²', $text);
        $this->assertStringContainsString('480,00 m²', $text);
        $this->assertStringContainsString('365 von 365 Tagen', $text);

        // Summen, Vorauszahlungen und Ergebnis
        $this->assertStringContainsString('Zwischensumme ohne Heizkosten', $text);
        $this->assertStringContainsString('Summe der umlagefähigen Kosten', $text);
        $this->assertStringContainsString('Abzüglich Ihrer tatsächlich geleisteten Vorauszahlungen', $text);
        $this->assertStringContainsString('Nachzahlung', $text);
    }

    public function test_heizkostenblock_wird_getrennt_ausgewiesen(): void
    {
        $text = PdfTextExtractor::text(
            $this->renderer->renderFinal(PdfFixtures::statementView())->contents
        );

        $this->assertStringContainsString('Heizkosten und Warmwasser', $text);
        $this->assertStringContainsString('Zwischensumme Heizkosten', $text);
    }

    public function test_betraege_und_datumsangaben_folgen_dem_deutschen_format(): void
    {
        $text = PdfTextExtractor::text(
            $this->renderer->renderFinal(PdfFixtures::statementView())->contents
        );

        $this->assertStringContainsString('1.234,56', $text);
        $this->assertMatchesRegularExpression('/\d{1,3}(\.\d{3})*,\d{2} EUR/', $text);
        $this->assertMatchesRegularExpression('/\d{2}\.\d{2}\.\d{4}/', $text);
        $this->assertStringNotContainsString('EUR 1234.56', $text);
    }

    public function test_guthaben_wird_als_erstattung_gekennzeichnet(): void
    {
        $result = PdfFixtures::statementResult(null, [], [], 300000);
        $text = PdfTextExtractor::text(
            $this->renderer->renderFinal(PdfFixtures::statementView($result))->contents
        );

        $this->assertTrue($result->isCredit());
        $this->assertStringContainsString('Guthaben zu Ihren Gunsten', $text);
        $this->assertStringContainsString('wird Ihnen erstattet', $text);
    }

    public function test_bankverbindung_des_vermieters_ist_optional(): void
    {
        $mit = PdfTextExtractor::text($this->renderer->renderFinal(PdfFixtures::statementView())->contents);
        $ohne = PdfTextExtractor::text(
            $this->renderer->renderFinal(PdfFixtures::statementView(null, false, false))->contents
        );

        $this->assertStringContainsString('DE02120300000000202051', $mit);
        $this->assertStringNotContainsString('DE02120300000000202051', $ohne);
    }

    public function test_kennzeichnung_bei_fehlender_zwischenablesung_wird_gedruckt(): void
    {
        $lines = PdfFixtures::defaultLines();
        $lines[] = PdfFixtures::substituteDistributionLine();

        $text = PdfTextExtractor::text(
            $this->renderer->renderFinal(
                PdfFixtures::statementView(PdfFixtures::statementResult($lines))
            )->contents
        );

        $this->assertStringContainsString('Bestätigte Ersatzverteilung', $text);
        $this->assertStringContainsString('keine Zwischenablesung', $text);
    }

    public function test_allgemeine_rechtliche_hinweise_sind_in_jeder_mieterabrechnung_enthalten(): void
    {
        $text = PdfTextExtractor::text(
            $this->renderer->renderFinal(PdfFixtures::statementView())->contents
        );

        $this->assertStringContainsString('Belege einzusehen', $text);
        $this->assertStringContainsString('zwölf Monaten nach Zugang', $text);
        $this->assertStringContainsString('Originalbelege werden vom Vermieter selbst aufbewahrt', $text);
        $this->assertStringContainsString('keine Rechtsberatung', $text);
    }

    public function test_mieterabrechnung_traegt_nur_die_dezente_fusszeile_und_kein_hvm_ci(): void
    {
        $html = $this->renderer->html(PdfFixtures::statementView(null, true));
        $document = $this->renderer->renderFinal(PdfFixtures::statementView());
        $text = PdfTextExtractor::text($document->contents);

        foreach ([
            HvmCorporateIdentity::ORANGE,
            HvmCorporateIdentity::ANTHRAZIT,
            HvmCorporateIdentity::MITTELGRAU,
            HvmCorporateIdentity::HELLGRAU,
        ] as $farbe) {
            $this->assertStringNotContainsStringIgnoringCase($farbe, $html);
        }

        $this->assertStringNotContainsString(HvmCorporateIdentity::LOGO_RELATIVE_PATH, $html);
        $this->assertStringNotContainsString('Logo_HVM', $html);
        $this->assertStringNotContainsString('kennlinie', $html);
        $this->assertStringNotContainsString('Hausverwaltung Müller', $text);
        $this->assertStringContainsString('Erstellt über smart-abrechnen.de', $text);
    }

    public function test_anlage_der_verteilerschluessel_wird_angehaengt(): void
    {
        $text = PdfTextExtractor::text(
            $this->renderer->renderFinal(PdfFixtures::statementView())->contents
        );

        $this->assertStringContainsString('Anlage: Erläuterung der Verteilerschlüssel', $text);
        $this->assertStringContainsString('Personentage', $text);
        $this->assertStringContainsString('Zeitanteilige Berechnung', $text);
    }

    public function test_belegliste_wird_nur_bei_zuschaltung_angehaengt(): void
    {
        $ohne = PdfTextExtractor::text(
            $this->renderer->renderFinal(PdfFixtures::statementView())->contents
        );
        $mit = PdfTextExtractor::text(
            $this->renderer->renderFinal(PdfFixtures::statementView(null, true))->contents
        );

        $this->assertStringNotContainsString('Anlage: Belegübersicht', $ohne);
        $this->assertStringContainsString('Anlage: Belegübersicht', $mit);
    }

    public function test_langes_ergebnis_bricht_ueber_mehrere_seiten_mit_wiederholtem_tabellenkopf_um(): void
    {
        $view = PdfFixtures::statementView(PdfFixtures::statementResult(PdfFixtures::manyLines(60)));

        $document = $this->renderer->renderFinal($view);
        $text = PdfTextExtractor::text($document->contents);

        $this->assertGreaterThanOrEqual(4, $document->pageCount);
        $this->assertSame($document->pageCount, PdfTextExtractor::pageCount($document->contents));
        $this->assertGreaterThanOrEqual(2, substr_count($text, 'Gesamtkosten EUR'));
        $this->assertSame($document->pageCount, substr_count($text, 'Seite '));
        $this->assertStringContainsString('Seite 1 von '.$document->pageCount, $text);
        $this->assertStringContainsString('Beispielkostenart Nummer 60', $text);
    }

    public function test_dateiname_ist_neutral_und_sprechend(): void
    {
        $vorschau = $this->renderer->renderPreview(PdfFixtures::statementView());
        $final = $this->renderer->renderFinal(PdfFixtures::statementView());

        $this->assertSame(
            'betriebskostenabrechnung-2025-wohnung-3-beispielmieterin-vorschau.pdf',
            $vorschau->downloadName
        );
        $this->assertSame(
            'betriebskostenabrechnung-2025-wohnung-3-beispielmieterin.pdf',
            $final->downloadName
        );
    }

    public function test_pruefhinweise_aus_dem_ergebnisobjekt_werden_gedruckt(): void
    {
        $result = PdfFixtures::statementResult(null, [], [
            CheckFinding::warning(
                CheckCode::PREPAYMENT_DEVIATION,
                'Die geleisteten Vorauszahlungen weichen von den vereinbarten Sollwerten ab.'
            ),
        ]);

        $text = PdfTextExtractor::text(
            $this->renderer->renderFinal(PdfFixtures::statementView($result))->contents
        );

        $this->assertStringContainsString('Hinweise aus der Prüfung', $text);
        $this->assertStringContainsString('weichen von den vereinbarten Sollwerten ab', $text);
    }

    public function test_ergebnis_entspricht_exakt_dem_berechnungsergebnis(): void
    {
        $result = PdfFixtures::statementResult();
        $text = PdfTextExtractor::text(
            $this->renderer->renderFinal(PdfFixtures::statementView($result))->contents
        );

        $this->assertStringContainsString($result->allocableTotal->format(), $text);
        $this->assertStringContainsString($result->prepaymentActual->format(), $text);
        $this->assertStringContainsString($result->additionalPayment()->format(), $text);
        $this->assertTrue($result->balance->equals($result->allocableTotal->minus($result->prepaymentActual)));
        $this->assertTrue($result->allocableTotal->isGreaterThan(Money::zero()));
    }
}
