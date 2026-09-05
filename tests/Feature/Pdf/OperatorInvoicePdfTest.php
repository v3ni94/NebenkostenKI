<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Services\Pdf\Renderer\OperatorInvoiceRenderer;
use App\Services\Pdf\Support\HvmCorporateIdentity;
use App\Services\Pdf\View\InvoiceView;
use App\Services\Storage\ArtifactType;
use Tests\TestCase;

/**
 * HVM-Rechnung an den Nutzer (Abschnitt 15.2 und 18).
 */
class OperatorInvoicePdfTest extends TestCase
{
    private OperatorInvoiceRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = app(OperatorInvoiceRenderer::class);
    }

    public function test_rechnung_erzeugt_ein_gueltiges_pdf_ohne_wasserzeichen(): void
    {
        $document = $this->renderer->render(PdfFixtures::invoiceView());

        $this->assertStringStartsWith('%PDF-', $document->contents);
        $this->assertSame(ArtifactType::HVM_RECHNUNG, $document->artifactType);
        $this->assertSame('rechnung-nk-2026-000001.pdf', $document->downloadName);
        $this->assertSame($document->pageCount, PdfTextExtractor::pageCount($document->contents));

        $watermark = config('smartabrechnen.pdf.watermark_text');
        $this->assertIsString($watermark);
        $this->assertSame(0, PdfTextExtractor::occurrences($document->contents, $watermark));
    }

    public function test_rechnung_enthaelt_alle_pflichtangaben_der_position(): void
    {
        $text = PdfTextExtractor::text($this->renderer->render(PdfFixtures::invoiceView())->contents);

        $this->assertStringContainsString('NK-2026-000001', $text);
        $this->assertStringContainsString('Rechnungsdatum', $text);
        $this->assertStringContainsString('Leistungsdatum', $text);
        $this->assertStringContainsString('31.03.2026', $text);
        $this->assertStringContainsString('Beispiel Vermietung Sonnenweg', $text);
        $this->assertStringContainsString('Sonnenweg 4', $text);
        $this->assertStringContainsString('Erstellung Betriebskostenabrechnung', $text);
        $this->assertStringContainsString('Wohnanlage Rosenstraße 12', $text);
        $this->assertStringContainsString('01.01.2025 bis 31.12.2025', $text);
        $this->assertStringContainsString('Mieterabrechnung', $text);
        $this->assertStringContainsString('20,92', $text);
    }

    public function test_netto_umsatzsteuer_und_brutto_werden_getrennt_ausgewiesen(): void
    {
        $text = PdfTextExtractor::text($this->renderer->render(PdfFixtures::invoiceView())->contents);

        $this->assertStringContainsString('Nettobetrag', $text);
        $this->assertStringContainsString('83,68 EUR', $text);
        $this->assertStringContainsString('Umsatzsteuer 19,00 Prozent', $text);
        $this->assertStringContainsString('15,92 EUR', $text);
        $this->assertStringContainsString('Bruttobetrag', $text);
        $this->assertStringContainsString('99,60 EUR', $text);
    }

    public function test_zahlungsart_und_stripe_referenz_werden_ausgewiesen(): void
    {
        $text = PdfTextExtractor::text($this->renderer->render(PdfFixtures::invoiceView())->contents);

        $this->assertStringContainsString('Zahlungsart', $text);
        $this->assertStringContainsString('Kreditkarte über Stripe', $text);
        $this->assertStringContainsString('Stripe-Referenz', $text);
        $this->assertStringContainsString('pi_beispiel_1234567890', $text);
    }

    public function test_pflichtangaben_des_betreibers_stehen_exakt_in_der_rechnung(): void
    {
        $text = PdfTextExtractor::text($this->renderer->render(PdfFixtures::invoiceView())->contents);

        $this->assertStringContainsString('Hausverwaltung Müller GmbH', $text);
        $this->assertStringContainsString('Rheinpromenade 13', $text);
        $this->assertStringContainsString('40789 Monheim am Rhein', $text);
        $this->assertStringContainsString('Amtsgericht Düsseldorf, HRB 104762', $text);
        $this->assertStringContainsString('Geschäftsführer: Timo Müller', $text);
        $this->assertStringContainsString('https://www.muellerhv.de/', $text);
    }

    public function test_fehlende_steuer_und_bankdaten_erscheinen_als_sichtbarer_platzhalter(): void
    {
        config()->set('smartabrechnen.operator.tax_id', null);
        config()->set('smartabrechnen.operator.vat_id', null);
        config()->set('smartabrechnen.operator.iban', null);
        config()->set('smartabrechnen.operator.bic', null);

        $text = PdfTextExtractor::text($this->renderer->render(PdfFixtures::invoiceView())->contents);

        $platzhalter = config('smartabrechnen.operator.placeholder_text');
        $this->assertIsString($platzhalter);

        $this->assertSame(4, substr_count($text, $platzhalter));
        $this->assertStringContainsString('Steuernummer:', $text);
        $this->assertStringContainsString('Umsatzsteuer-Identifikationsnummer:', $text);
        $this->assertStringContainsString('IBAN:', $text);
        $this->assertStringContainsString('BIC:', $text);
        $this->assertNotSame([], $this->renderer->launchBlockers());
    }

    public function test_bestaetigte_steuerdaten_werden_unveraendert_gedruckt(): void
    {
        config()->set('smartabrechnen.operator.tax_id', '135/5678/9012');
        config()->set('smartabrechnen.operator.vat_id', 'DE123456789');
        config()->set('smartabrechnen.operator.iban', 'DE02120300000000202051');
        config()->set('smartabrechnen.operator.bic', 'BYLADEM1001');

        $text = PdfTextExtractor::text($this->renderer->render(PdfFixtures::invoiceView())->contents);

        $this->assertStringContainsString('135/5678/9012', $text);
        $this->assertStringContainsString('DE123456789', $text);
        $this->assertStringContainsString('DE02120300000000202051', $text);
        $this->assertStringContainsString('BYLADEM1001', $text);
        $this->assertSame([], $this->renderer->launchBlockers());
    }

    public function test_rechnung_traegt_die_hvm_kennlinie_und_den_logoplatzhalter(): void
    {
        $html = $this->renderer->html(PdfFixtures::invoiceView());

        $this->assertStringContainsString('kennlinie', $html);
        $this->assertStringContainsString(HvmCorporateIdentity::ANTHRAZIT, $html);
        $this->assertStringContainsString(HvmCorporateIdentity::ORANGE, $html);
        $this->assertStringContainsString(HvmCorporateIdentity::HELLGRAU, $html);
        $this->assertStringContainsString('3mm', $html);

        if (! HvmCorporateIdentity::hasLogo()) {
            $this->assertStringContainsString(HvmCorporateIdentity::LOGO_PLACEHOLDER, $html);
            $this->assertStringNotContainsString('<img', $html);
        }
    }

    public function test_stornorechnung_verweist_auf_die_urspruengliche_rechnung(): void
    {
        $original = PdfFixtures::invoiceView();
        $storno = new InvoiceView(
            'NK-2026-000002',
            $original->issuedOn,
            $original->serviceDate,
            $original->customer,
            $original->lines,
            $original->netTotal->negated(),
            $original->taxTotal->negated(),
            $original->grossTotal->negated(),
            $original->taxRatePercent,
            $original->paymentMethod,
            $original->paymentReference,
            null,
            'NK-2026-000001',
        );

        $text = PdfTextExtractor::text($this->renderer->render($storno)->contents);

        $this->assertTrue($storno->isCancellation());
        $this->assertStringContainsString('Stornorechnung NK-2026-000002', $text);
        $this->assertStringContainsString('Storniert Rechnung', $text);
        $this->assertStringContainsString('NK-2026-000001', $text);
        $this->assertStringContainsString('-99,60 EUR', $text);

        // Auf der Stornorechnung wurde kein negativer Betrag entrichtet.
        $this->assertStringNotContainsString('bereits vollständig entrichtet', $text);
        $this->assertStringContainsString('Erstattung erfolgt gesondert', $text);
    }

    public function test_die_rechnung_weist_den_betrag_als_bereits_entrichtet_aus(): void
    {
        $text = PdfTextExtractor::text($this->renderer->render(PdfFixtures::invoiceView())->contents);

        $this->assertStringContainsString('bereits vollständig entrichtet', $text);
        $this->assertStringNotContainsString('Erstattung erfolgt gesondert', $text);
    }
}
