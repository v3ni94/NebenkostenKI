<?php

declare(strict_types=1);

namespace Tests\Feature\Heating;

use App\Services\Pdf\Renderer\OwnerOverviewRenderer;
use App\Services\Pdf\Renderer\TenantStatementRenderer;
use App\Services\Pdf\View\OwnerOverviewView;
use App\Services\Pdf\View\TenantStatementView;
use DateTimeImmutable;
use Tests\Feature\Pdf\PdfFixtures;
use Tests\Feature\Pdf\PdfTextExtractor;
use Tests\TestCase;

/**
 * Vermerke zu manuell erfassten Heizkosten in der Mieterabrechnung und im
 * internen Blatt der Eigentuemeruebersicht.
 *
 * Beide Textbausteine sind im Blade als anwaltlich freizugeben gekennzeichnet.
 */
final class ManualHeatingPdfNoticeTest extends TestCase
{
    public function test_die_mieterabrechnung_vermerkt_die_uebernahme_der_heizkosten(): void
    {
        $document = app(TenantStatementRenderer::class)->renderFinal($this->tenantView(true));
        $text = PdfTextExtractor::text($document->contents);

        $this->assertStringContainsString('Die Heizkosten je Einheit wurden vom Vermieter ermittelt', $text);
        $this->assertStringContainsString('unverändert', $text);
    }

    public function test_ohne_manuelle_erfassung_erscheint_kein_vermerk_in_der_mieterabrechnung(): void
    {
        $document = app(TenantStatementRenderer::class)->renderFinal($this->tenantView(false));
        $text = PdfTextExtractor::text($document->contents);

        $this->assertStringNotContainsString('Die Heizkosten je Einheit wurden vom Vermieter ermittelt', $text);
    }

    public function test_der_textbaustein_der_mieterabrechnung_ist_anwaltlich_freizugeben(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 3).'/resources/views/pdf/mieterabrechnung.blade.php');

        $this->assertIsString($quelle);
        $this->assertStringContainsString('TEXTBAUSTEIN ANWALTLICH FREIZUGEBEN', $quelle);
    }

    public function test_die_eigentuemeruebersicht_nennt_herkunft_und_fehlende_pruefung(): void
    {
        $document = app(OwnerOverviewRenderer::class)->renderFinal($this->ownerView(
            true,
            'Eigene Tabellenkalkulation vom 15.03.2026'
        ));
        $text = PdfTextExtractor::text($document->contents);

        $this->assertStringContainsString('Manuell erfasste Heizkosten', $text);
        $this->assertStringContainsString('Eigene Tabellenkalkulation vom 15.03.2026', $text);
        $this->assertStringContainsString('Prüfung', $text);
        $this->assertStringContainsString('nicht erfolgt', $text);
    }

    public function test_ohne_erfasste_herkunft_wird_dies_offen_ausgewiesen(): void
    {
        $document = app(OwnerOverviewRenderer::class)->renderFinal($this->ownerView(true, null));
        $text = PdfTextExtractor::text($document->contents);

        $this->assertStringContainsString('Keine Angabe erfasst', $text);
    }

    public function test_ohne_manuelle_erfassung_erscheint_kein_vermerk_im_internen_blatt(): void
    {
        $document = app(OwnerOverviewRenderer::class)->renderFinal($this->ownerView(false, null));
        $text = PdfTextExtractor::text($document->contents);

        $this->assertStringNotContainsString('Manuell erfasste Heizkosten', $text);
    }

    public function test_der_textbaustein_des_internen_blattes_ist_anwaltlich_freizugeben(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 3).'/resources/views/pdf/eigentuemeruebersicht.blade.php');

        $this->assertIsString($quelle);
        $this->assertStringContainsString('TEXTBAUSTEIN ANWALTLICH FREIZUGEBEN', $quelle);
    }

    private function tenantView(bool $manuell): TenantStatementView
    {
        $vorlage = PdfFixtures::statementView();

        return new TenantStatementView(
            $vorlage->sender,
            $vorlage->recipient,
            $vorlage->subject,
            $vorlage->result,
            $vorlage->statementDate,
            $vorlage->heatingCategoryKeys,
            $vorlage->vouchers,
            $vorlage->showVoucherIndex,
            $vorlage->showBankAccount,
            $manuell,
        );
    }

    private function ownerView(bool $manuell, ?string $herkunft): OwnerOverviewView
    {
        $vorlage = PdfFixtures::ownerOverviewView();

        return new OwnerOverviewView(
            $vorlage->result,
            new DateTimeImmutable('2026-03-31'),
            $vorlage->owner,
            $vorlage->propertyAddressLine,
            $vorlage->findings,
            $vorlage->manualDecisions,
            $vorlage->documents,
            $vorlage->billingRunReference,
            $manuell,
            $herkunft,
        );
    }
}
