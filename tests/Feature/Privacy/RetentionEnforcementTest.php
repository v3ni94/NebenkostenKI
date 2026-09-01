<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Application\Privacy\Dto\RetentionReport;
use App\Application\Privacy\EnforceRetention;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentVariant;
use App\Models\BillingRun;
use App\Models\DocumentPage;
use App\Models\ExtractedField;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Durchsetzung der Aufbewahrungsfristen (Masterprompt 19).
 *
 * Verbindlich: Sind die Fristen nicht gesetzt, wird NICHTS geloescht und der
 * Lauf weist auf die offene Festlegung hin.
 */
final class RetentionEnforcementTest extends PrivacyTestCase
{
    public function test_ohne_gesetzte_fristen_wird_nichts_geloescht(): void
    {
        $a = $this->mandant('A');

        config([
            'smartabrechnen.retention.extracted_data_days' => null,
            'smartabrechnen.retention.generated_pdf_days' => null,
        ]);

        $alt = $this->altesFeld($a);
        $altesPdf = $this->altesPdf($a);

        $bericht = $this->fuehreAus();

        self::assertFalse($bericht->extractedDataConfigured());
        self::assertFalse($bericht->generatedPdfConfigured());
        self::assertSame(0, $bericht->deletedExtractedFields);
        self::assertSame(0, $bericht->deletedGeneratedDocuments);

        $this->assertDatabaseHas('extracted_fields', ['id' => $alt->getKey()]);
        $this->assertDatabaseHas('generated_documents', ['id' => $altesPdf->getKey()]);
        self::assertTrue(Storage::disk('local')->exists((string) $altesPdf->getAttribute('storage_path')));
    }

    public function test_command_weist_auf_die_fehlenden_fristen_hin(): void
    {
        config([
            'smartabrechnen.retention.extracted_data_days' => null,
            'smartabrechnen.retention.generated_pdf_days' => null,
        ]);

        $this->artisan('smartabrechnen:enforce-retention')
            ->expectsOutputToContain('EXTRACTED_DATA_RETENTION_DAYS')
            ->expectsOutputToContain('GENERATED_PDF_RETENTION_DAYS')
            ->assertExitCode(0);
    }

    public function test_abgelaufene_extraktionsdaten_werden_geloescht(): void
    {
        $a = $this->mandant('A');

        config(['smartabrechnen.retention.extracted_data_days' => 30]);

        $alt = $this->altesFeld($a, 90);
        $neu = $a['field'];

        $seiteAlt = $this->alteSeite($a, 90);
        $seiteNeu = $this->alteSeite($a, 1);

        $bericht = $this->fuehreAus();

        self::assertTrue($bericht->extractedDataConfigured());
        self::assertSame(1, $bericht->deletedExtractedFields);
        self::assertSame(1, $bericht->deletedDocumentPages);

        $this->assertDatabaseMissing('extracted_fields', ['id' => $alt->getKey()]);
        $this->assertDatabaseHas('extracted_fields', ['id' => $neu->getKey()]);
        $this->assertDatabaseMissing('document_pages', ['id' => $seiteAlt->getKey()]);
        $this->assertDatabaseHas('document_pages', ['id' => $seiteNeu->getKey()]);
    }

    public function test_abgelaufene_erzeugte_pdfs_werden_mit_datei_geloescht(): void
    {
        $a = $this->mandant('A');

        config(['smartabrechnen.retention.generated_pdf_days' => 365]);

        $altesPdf = $this->altesPdf($a, 400);
        $pfad = (string) $altesPdf->getAttribute('storage_path');

        $bericht = $this->fuehreAus();

        self::assertSame(1, $bericht->deletedGeneratedDocuments);
        self::assertSame(1, $bericht->deletedArtifacts);

        $this->assertDatabaseMissing('generated_documents', ['id' => $altesPdf->getKey()]);
        self::assertFalse(Storage::disk('local')->exists($pfad));

        // Das aktuelle PDF bleibt unberuehrt.
        $this->assertDatabaseHas('generated_documents', ['id' => $a['statementPdf']->getKey()]);
        self::assertTrue(Storage::disk('local')->exists(
            (string) $a['statementPdf']->getAttribute('storage_path')
        ));
    }

    public function test_hvm_rechnungen_sind_von_der_frist_ausgenommen(): void
    {
        $a = $this->mandant('A');

        config(['smartabrechnen.retention.generated_pdf_days' => 30]);

        GeneratedDocument::query()
            ->whereKey($a['invoicePdf']->getKey())
            ->update(['generated_at' => Carbon::now()->subDays(4000)]);

        $this->fuehreAus();

        $this->assertDatabaseHas('generated_documents', ['id' => $a['invoicePdf']->getKey()]);
        self::assertTrue(Storage::disk('local')->exists(
            (string) $a['invoicePdf']->getAttribute('storage_path')
        ));
    }

    public function test_lauf_ist_idempotent(): void
    {
        $a = $this->mandant('A');

        config([
            'smartabrechnen.retention.extracted_data_days' => 30,
            'smartabrechnen.retention.generated_pdf_days' => 365,
        ]);

        $this->altesFeld($a, 90);
        $this->altesPdf($a, 400);

        $erster = $this->fuehreAus();
        $zweiter = $this->fuehreAus();

        self::assertSame(1, $erster->deletedExtractedFields);
        self::assertSame(1, $erster->deletedGeneratedDocuments);
        self::assertSame(0, $zweiter->deletedExtractedFields);
        self::assertSame(0, $zweiter->deletedGeneratedDocuments);
    }

    public function test_command_meldet_das_ergebnis(): void
    {
        $a = $this->mandant('A');

        config([
            'smartabrechnen.retention.extracted_data_days' => 30,
            'smartabrechnen.retention.generated_pdf_days' => 365,
        ]);

        $this->altesFeld($a, 90);

        $this->artisan('smartabrechnen:enforce-retention')
            ->expectsOutputToContain('Aufbewahrung:')
            ->assertExitCode(0);
    }

    public function test_frist_null_oder_negativ_loescht_nichts(): void
    {
        $a = $this->mandant('A');

        config([
            'smartabrechnen.retention.extracted_data_days' => 0,
            'smartabrechnen.retention.generated_pdf_days' => -5,
        ]);

        $alt = $this->altesFeld($a, 900);

        $bericht = $this->fuehreAus();

        self::assertNull($bericht->extractedDataDays);
        self::assertNull($bericht->generatedPdfDays);
        self::assertFalse($bericht->fullyConfigured());
        $this->assertDatabaseHas('extracted_fields', ['id' => $alt->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $mandant
     */
    private function altesFeld(array $mandant, int $tage = 90): ExtractedField
    {
        /** @var ExtractedField $feld */
        $feld = ExtractedField::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'billing_run_id' => $mandant['billingRun']->getKey(),
            'document_id' => $mandant['document']->getKey(),
            'schema_key' => 'altes_feld_'.$tage,
        ]);

        ExtractedField::query()->whereKey($feld->getKey())->update([
            'created_at' => Carbon::now()->subDays($tage),
        ]);

        return $feld;
    }

    /**
     * @param  array<string, mixed>  $mandant
     */
    private function alteSeite(array $mandant, int $tage): DocumentPage
    {
        /** @var DocumentPage $seite */
        $seite = DocumentPage::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'document_id' => $mandant['document']->getKey(),
            'page_number' => $tage,
        ]);

        DocumentPage::query()->whereKey($seite->getKey())->update([
            'created_at' => Carbon::now()->subDays($tage),
        ]);

        return $seite;
    }

    /**
     * @param  array<string, mixed>  $mandant
     */
    private function altesPdf(array $mandant, int $tage = 400): GeneratedDocument
    {
        $organisation = $mandant['organization'];
        $lauf = $mandant['billingRun'];

        self::assertInstanceOf(Organization::class, $organisation);
        self::assertInstanceOf(BillingRun::class, $lauf);

        $dokument = $this->artefakt(
            $organisation,
            $lauf,
            GeneratedDocumentKind::MIETERABRECHNUNG,
            GeneratedDocumentVariant::VORSCHAU,
            'abrechnungen/vorschau/alt-'.$tage.'.pdf',
            '%PDF-1.4 alte Vorschau',
        );

        GeneratedDocument::query()->whereKey($dokument->getKey())->update([
            'generated_at' => Carbon::now()->subDays($tage),
        ]);

        return $dokument;
    }

    private function fuehreAus(): RetentionReport
    {
        /** @var EnforceRetention $use */
        $use = app(EnforceRetention::class);

        return $use();
    }
}
