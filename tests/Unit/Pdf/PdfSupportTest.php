<?php

declare(strict_types=1);

namespace Tests\Unit\Pdf;

use App\Enums\GeneratedDocumentVariant;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\Support\GermanDate;
use App\Services\Pdf\Support\HvmCorporateIdentity;
use App\Services\Pdf\Support\OperatorDetails;
use App\Services\Pdf\Support\PdfFileName;
use App\Services\Pdf\View\DocumentIndexEntry;
use App\Services\Pdf\View\PostalAddress;
use App\Services\Pdf\Watermark\WatermarkSettings;
use App\Services\Storage\ArtifactType;
use DateTimeImmutable;
use Tests\TestCase;

/**
 * Hilfsklassen der PDF-Erzeugung: Formate, Pflichtangaben und Platzhalter.
 */
class PdfSupportTest extends TestCase
{
    public function test_datum_wird_als_tag_punkt_monat_punkt_jahr_ausgegeben(): void
    {
        $this->assertSame('31.03.2026', GermanDate::format(new DateTimeImmutable('2026-03-31')));
        $this->assertSame('', GermanDate::format(null));
        $this->assertSame('nicht ausgewiesen', GermanDate::formatOr(null, 'nicht ausgewiesen'));
    }

    public function test_anschriftfeld_enthaelt_keine_leerzeilen(): void
    {
        $adresse = new PostalAddress('Frau Beispiel', null, 'Rosenstraße 12', '40764', 'Musterstadt');

        $this->assertSame(
            ['Frau Beispiel', 'Rosenstraße 12', '40764 Musterstadt'],
            $adresse->lines()
        );
        $this->assertSame('Frau Beispiel, Rosenstraße 12, 40764 Musterstadt', $adresse->senderLine());
    }

    public function test_dateiname_ist_neutral_und_ohne_umlaute(): void
    {
        $name = PdfFileName::build('Betriebskostenabrechnung', '2025', 'Wohnung Grün 3');

        $this->assertSame('betriebskostenabrechnung-2025-wohnung-gruen-3.pdf', $name);
        $this->assertStringEndsWith('.zip', PdfFileName::zip('Paket', '2025'));
    }

    public function test_fehlende_steuerdaten_erscheinen_als_sichtbarer_platzhalter(): void
    {
        config()->set('smartabrechnen.operator.tax_id', null);
        config()->set('smartabrechnen.operator.vat_id', '');
        config()->set('smartabrechnen.operator.iban', null);

        $operator = OperatorDetails::fromConfig();

        $this->assertSame('[vor Livegang ergänzen]', $operator->taxId());
        $this->assertSame('[vor Livegang ergänzen]', $operator->vatId());
        $this->assertSame('[vor Livegang ergänzen]', $operator->iban());
        $this->assertFalse($operator->has('tax_id'));
    }

    public function test_fehlende_pflichtangaben_werden_als_livegang_blocker_benannt(): void
    {
        config()->set('smartabrechnen.operator.tax_id', null);
        config()->set('smartabrechnen.operator.vat_id', null);
        config()->set('smartabrechnen.operator.iban', null);
        config()->set('smartabrechnen.operator.bic', null);
        config()->set('smartabrechnen.operator.masterdata_confirmed', false);

        $operator = OperatorDetails::fromConfig();

        $this->assertTrue($operator->isLaunchBlocked());
        $this->assertSame(
            ['Steuernummer', 'Umsatzsteuer-Identifikationsnummer', 'IBAN', 'BIC'],
            $operator->missingMandatoryFields()
        );
    }

    public function test_bestaetigte_stammdaten_ohne_luecken_blockieren_nicht(): void
    {
        config()->set('smartabrechnen.operator.tax_id', '135/5678/9012');
        config()->set('smartabrechnen.operator.vat_id', 'DE123456789');
        config()->set('smartabrechnen.operator.iban', 'DE02120300000000202051');
        config()->set('smartabrechnen.operator.bic', 'BYLADEM1001');
        config()->set('smartabrechnen.operator.masterdata_confirmed', true);

        $operator = OperatorDetails::fromConfig();

        $this->assertSame([], $operator->missingMandatoryFields());
        $this->assertFalse($operator->isLaunchBlocked());
    }

    public function test_pflichtangaben_des_betreibers_stammen_unveraendert_aus_der_konfiguration(): void
    {
        $operator = OperatorDetails::fromConfig();

        $this->assertSame('Hausverwaltung Müller GmbH', $operator->legalName());
        $this->assertSame('Rheinpromenade 13', $operator->addressLine());
        $this->assertSame('40789 Monheim am Rhein', $operator->cityLine());
        $this->assertSame('Amtsgericht Düsseldorf, HRB 104762', $operator->registerLine());
        $this->assertSame('Geschäftsführer: Timo Müller', $operator->managingDirectorLine());
    }

    public function test_wasserzeichen_der_vorschau_kommt_aus_der_konfiguration(): void
    {
        $settings = WatermarkSettings::preview();

        $this->assertTrue($settings->enabled);
        $this->assertSame(config('smartabrechnen.pdf.watermark_text'), $settings->text);
        $this->assertSame(config('smartabrechnen.pdf.watermark_footer'), $settings->footerNote);
        $this->assertGreaterThan(0.0, $settings->alpha);
        $this->assertLessThan(0.5, $settings->alpha);
    }

    public function test_finalversion_hat_keine_wasserzeicheneinstellung(): void
    {
        $settings = WatermarkSettings::none();

        $this->assertFalse($settings->enabled);
        $this->assertSame('', $settings->text);
        $this->assertSame('', $settings->footerNote);
    }

    public function test_hvm_logo_wird_nur_bei_vorhandener_datei_eingebunden(): void
    {
        $vorhanden = is_file(public_path(HvmCorporateIdentity::LOGO_RELATIVE_PATH));

        $this->assertSame($vorhanden, HvmCorporateIdentity::hasLogo());

        if (! $vorhanden) {
            $this->assertNull(HvmCorporateIdentity::logoPath());
            $this->assertStringContainsString('Logo folgt vor Livegang', HvmCorporateIdentity::LOGO_PLACEHOLDER);
        }
    }

    public function test_hvm_kennlinie_folgt_den_ci_abschnitten(): void
    {
        $segmente = HvmCorporateIdentity::keylineSegments();

        $this->assertCount(4, $segmente);
        $this->assertSame(HvmCorporateIdentity::ANTHRAZIT, $segmente[0]['color']);
        $this->assertSame('40%', $segmente[0]['width']);
        $this->assertSame(HvmCorporateIdentity::ORANGE, $segmente[2]['color']);
        $this->assertSame('7.5%', $segmente[2]['width']);
        $this->assertSame('3mm', HvmCorporateIdentity::keylineHeightMm());
    }

    public function test_pdf_dokument_liefert_nachweisangaben(): void
    {
        $document = new PdfDocument(
            ArtifactType::MIETERABRECHNUNG_FINAL,
            GeneratedDocumentVariant::FINAL,
            '%PDF-1.7 Beispielinhalt',
            2,
            '1.0.0',
            new DateTimeImmutable('2026-03-31'),
            'snapshot-1',
            'beispiel.pdf',
        );

        $this->assertTrue($document->isPdf());
        $this->assertSame(hash('sha256', '%PDF-1.7 Beispielinhalt'), $document->sha256());
        $this->assertSame(23, $document->byteSize());
        $this->assertFalse($document->hasWatermarkVariant());
    }

    public function test_pruefsumme_wird_in_der_dokumentenuebersicht_verkuerzt(): void
    {
        $eintrag = new DocumentIndexEntry('Mieterabrechnung', 'Finalversion', 'Mieterin', null, str_repeat('b', 64));

        $this->assertSame(str_repeat('b', 16).'…', $eintrag->shortSha256());
        $this->assertSame('', (new DocumentIndexEntry('X', 'Y'))->shortSha256());
    }
}
