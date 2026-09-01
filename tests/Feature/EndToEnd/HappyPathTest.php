<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use App\Application\Reminder\ReminderLinks;
use App\Enums\BillingRunStatus;
use App\Enums\DeletionStatus;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\PaymentStatus;
use App\Mail\FinalabrechnungenVerfuegbarMail;
use App\Mail\HvmRechnungVerfuegbarMail;
use App\Mail\ZahlungBestaetigtMail;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\EmailMessage;
use App\Models\ExtractedField;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\TemporaryUpload;
use App\Models\User;
use App\Services\Storage\ArtifactStorage;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\PdfTextExtractor;

/**
 * Durchgehender Happy Path nach Abschnitt 23.4 und 24.
 *
 * Der Test laeuft ueber die echten Routen und die echte Verdrahtung:
 * Registrierung, Verifizierung, Objekt, Einheit, Mietverhaeltnis,
 * Abrechnungslauf, Upload von Hausgeldabrechnung und Grundsteuerbescheid,
 * Auswertung mit dem Testprovider, Analyse, Kostenpruefung, Vorauszahlungen,
 * Verteilerschluessel, Pruefbericht, Vorschau mit Wasserzeichen,
 * Nutzerbestaetigung, Checkout, signaturgepruefter Webhook, Finalisierung,
 * Download der Finaldokumente und des Pakets, HVM-Rechnung und
 * Folgejahreseinstieg.
 */
final class HappyPathTest extends EndToEndTestCase
{
    public function test_der_durchlauf_fuehrt_vom_konto_bis_zu_den_finalen_abrechnungen(): void
    {
        $welt = $this->fuehreHappyPathAus();

        // Schritt 3 und 6: aus beiden Unterlagen entstand je eine Position.
        self::assertSame(2, Document::query()->count());
        self::assertSame(2, $welt['positionen']);

        // Die Grundsteuer wurde ergaenzt, weil sie in der Hausgeldabrechnung
        // nicht enthalten ist (Abschnitt 7.3).
        self::assertNotNull(
            CostItem::query()
                ->where('billing_run_id', $welt['billingRun']->getKey())
                ->where('description', 'like', 'Grundsteuer%')
                ->first()
        );

        // Schritt 11: bezahlt wurde genau eine Mieterabrechnung.
        self::assertSame(PaymentStatus::BEZAHLT, $welt['payment']->getAttribute('status'));
        self::assertSame(1, (int) $welt['payment']->getAttribute('statement_count'));
        self::assertSame(2490, (int) $welt['payment']->getAttribute('amount_cent'));

        // Schritt 12: der Lauf ist finalisiert.
        $lauf = BillingRun::query()->findOrFail($welt['billingRun']->getKey());

        self::assertSame(BillingRunStatus::FINALIZED, $lauf->getAttribute('status'));
        self::assertNotNull($lauf->getAttribute('paid_at'));

        $snapshotId = (string) $lauf->getAttribute('active_calculation_snapshot_id');

        self::assertNotSame('', $snapshotId);

        $final = GeneratedDocument::query()
            ->where('billing_run_id', $lauf->getKey())
            ->where('variant', GeneratedDocumentVariant::FINAL->value)
            ->where('status', GeneratedDocumentStatus::AKTIV->value)
            ->get();

        $arten = $final->map(
            static fn (GeneratedDocument $dokument): string => $dokument->getAttribute('kind')->value
        )->all();

        self::assertContains(GeneratedDocumentKind::MIETERABRECHNUNG->value, $arten);
        self::assertContains(GeneratedDocumentKind::EIGENTUEMERUEBERSICHT->value, $arten);
        self::assertContains(GeneratedDocumentKind::ZIP_PAKET->value, $arten);
        self::assertContains(GeneratedDocumentKind::HVM_RECHNUNG->value, $arten);
    }

    public function test_die_vorschau_traegt_ein_wasserzeichen_und_die_finalversion_nicht(): void
    {
        $welt = $this->fuehreHappyPathAus();
        $ablage = new ArtifactStorage;

        $vorschau = GeneratedDocument::query()
            ->where('billing_run_id', $welt['billingRun']->getKey())
            ->where('kind', GeneratedDocumentKind::MIETERABRECHNUNG->value)
            ->where('variant', GeneratedDocumentVariant::VORSCHAU->value)
            ->firstOrFail();

        $inhalt = $ablage->get((string) $vorschau->getAttribute('storage_path'));

        self::assertIsString($inhalt);
        self::assertStringContainsString('VORSCHAU', PdfTextExtractor::text($inhalt));

        $endgueltig = GeneratedDocument::query()
            ->where('billing_run_id', $welt['billingRun']->getKey())
            ->where('kind', GeneratedDocumentKind::MIETERABRECHNUNG->value)
            ->where('variant', GeneratedDocumentVariant::FINAL->value)
            ->firstOrFail();

        $finalInhalt = $ablage->get((string) $endgueltig->getAttribute('storage_path'));

        self::assertIsString($finalInhalt);
        self::assertStringStartsWith('%PDF-', $finalInhalt);

        $wasserzeichen = config('smartabrechnen.pdf.watermark_text');

        self::assertIsString($wasserzeichen);
        self::assertStringNotContainsString($wasserzeichen, PdfTextExtractor::text($finalInhalt));

        // Die Finalversion haengt am gesperrten Berechnungsstand und ist keine
        // Ableitung der Vorschaudatei.
        self::assertSame(
            (string) $welt['billingRun']->refresh()->getAttribute('active_calculation_snapshot_id'),
            (string) $endgueltig->getAttribute('calculation_snapshot_id'),
        );
        self::assertNotSame(
            (string) $vorschau->getAttribute('sha256'),
            (string) $endgueltig->getAttribute('sha256'),
        );
    }

    public function test_nach_der_extraktion_sind_die_originaldateien_fort_und_die_werte_erhalten(): void
    {
        $welt = $this->fuehreHappyPathAus();

        // 1. Keine Originaldatei, kein Seitenbild, kein OCR-Text im Storage.
        $ablage = Storage::disk(TemporaryUploadStorage::DISK);

        foreach ($welt['praefixe'] as $praefix) {
            self::assertNotSame('', $praefix);
            self::assertFalse($ablage->exists($praefix.'/original.bin'));
            self::assertFalse($ablage->exists($praefix));
        }

        self::assertSame([], $ablage->allFiles());

        // 2. Der Kurzzeitdatensatz ist ein inhaltsloser Tombstone. Die Datei
        //    ist damit auch nicht mehr adressierbar.
        foreach (TemporaryUpload::query()->get() as $upload) {
            self::assertTrue($upload->getAttribute('is_tombstone'));
            self::assertNull($upload->getAttribute('storage_key'));
        }

        // 3. Der Abruf der Unterlage liefert keine Datei mehr.
        foreach (TemporaryUpload::query()->get() as $upload) {
            $antwort = $this->actingAs($welt['user'])
                ->getJson(route('portal.uploads.show', ['upload' => $upload->getKey()]));

            self::assertNotSame('application/pdf', $antwort->headers->get('content-type'));
            self::assertStringNotContainsString('%PDF', $antwort->content());
        }

        // 4. Die Loeschung ist je Unterlage protokolliert.
        foreach (Document::query()->get() as $dokument) {
            self::assertSame(DeletionStatus::ERFOLGREICH, $dokument->getAttribute('deletion_status'));
        }

        // 5. Die strukturierten Werte fuer die Abrechnung sind erhalten.
        self::assertGreaterThan(20, ExtractedField::query()->count());

        $hausgeld = Document::query()
            ->where('document_type', 'WEG_HAUSGELDABRECHNUNG_EINZEL')
            ->firstOrFail();

        self::assertSame(
            ['wert' => 372000],
            ExtractedField::query()
                ->where('document_id', $hausgeld->getKey())
                ->where('schema_key', 'hausgeldvorauszahlungen_cent')
                ->value('value'),
        );

        $grundsteuer = Document::query()
            ->where('document_type', 'GRUNDSTEUERBESCHEID')
            ->firstOrFail();

        self::assertSame(
            ['wert' => 24960],
            ExtractedField::query()
                ->where('document_id', $grundsteuer->getKey())
                ->where('schema_key', 'jahresbetrag_cent')
                ->value('value'),
        );

        // 6. Und sie sind in der Abrechnung angekommen: die Grundsteuer steht
        //    mit genau dem ausgelesenen Betrag als Kostenposition.
        self::assertSame(
            24960,
            (int) CostItem::query()
                ->where('billing_run_id', $welt['billingRun']->getKey())
                ->where('description', 'like', 'Grundsteuer%')
                ->value('amount_cent'),
        );
    }

    public function test_die_finalen_dateien_und_das_paket_sind_abrufbar(): void
    {
        $welt = $this->fuehreHappyPathAus();

        $dokumente = GeneratedDocument::query()
            ->where('billing_run_id', $welt['billingRun']->getKey())
            ->where('variant', GeneratedDocumentVariant::FINAL->value)
            ->where('status', GeneratedDocumentStatus::AKTIV->value)
            ->get();

        self::assertGreaterThanOrEqual(4, $dokumente->count());

        foreach ($dokumente as $dokument) {
            $this->actingAs($welt['user'])
                ->get(route('portal.downloads.stream', ['generatedDocument' => $dokument->getKey()]))
                ->assertOk();
        }

        $this->actingAs($welt['user'])
            ->get(route('portal.abschluss.show', ['billingRun' => $welt['billingRun']->getKey()]))
            ->assertOk()
            ->assertSee('Rechnung');

        $rechnung = Invoice::query()->where('billing_run_id', $welt['billingRun']->getKey())->firstOrFail();

        self::assertSame(2490, (int) $rechnung->getAttribute('gross_cent'));
        self::assertNotSame('', (string) $rechnung->getAttribute('number'));
    }

    public function test_die_bestaetigungsmails_gehen_mit_downloadlink_und_ohne_mieterabrechnung(): void
    {
        Mail::fake();

        $welt = $this->fuehreHappyPathAus();

        Mail::assertSent(ZahlungBestaetigtMail::class);
        Mail::assertSent(HvmRechnungVerfuegbarMail::class);

        // Die Zahlungsbestaetigung darf ausschliesslich die HVM-Rechnung
        // anhaengen, niemals eine Mieterabrechnung.
        Mail::assertSent(ZahlungBestaetigtMail::class, function (ZahlungBestaetigtMail $mail): bool {
            foreach ($mail->anhangDokumente() as $dokument) {
                self::assertSame(GeneratedDocumentKind::HVM_RECHNUNG, $dokument->getAttribute('kind'));
            }

            return true;
        });

        // Die Finalabrechnungen gehen ausschliesslich als zeitlich begrenzter
        // signierter Downloadlink hinaus.
        Mail::assertSent(FinalabrechnungenVerfuegbarMail::class, function (
            FinalabrechnungenVerfuegbarMail $mail
        ) use ($welt): bool {
            self::assertSame([], $mail->anhangDokumente());

            $daten = $mail->daten();
            $url = $daten['downloadUrl'];

            self::assertIsString($url);
            self::assertStringContainsString('/signiert', $url);
            self::assertStringContainsString('signature=', $url);
            self::assertStringContainsString('expires=', $url);
            self::assertStringNotContainsString((string) $welt['user']->getAttribute('email'), $url);
            self::assertIsInt($daten['gueltigkeitMinuten']);
            self::assertGreaterThan(0, $daten['gueltigkeitMinuten']);

            // Der Link ist kontogebunden: er wirkt nur fuer den eigenen
            // Mandanten und ersetzt die Autorisierung nicht.
            $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
            $this->actingAs($welt['user'])->get($url)->assertOk();

            return true;
        });

        // Jeder Versand ist protokolliert.
        self::assertSame(
            ['finalabrechnungen-verfuegbar', 'hvm-rechnung-verfuegbar', 'zahlung-bestaetigt'],
            EmailMessage::query()->orderBy('template')->pluck('template')->unique()->values()->all(),
        );
    }

    public function test_das_konto_bietet_nach_dem_abschluss_den_folgejahreseinstieg(): void
    {
        $welt = $this->fuehreHappyPathAus();

        // Der abgeschlossene Lauf bleibt dauerhaft im Konto sichtbar.
        $this->actingAs($welt['user'])
            ->get(route('portal.dashboard'))
            ->assertOk();

        $url = app(ReminderLinks::class)->folgejahrUrl($welt['property'], 2026);

        $antwort = $this->actingAs($welt['user'])->get($url);

        $folgejahr = BillingRun::query()
            ->where('property_id', $welt['property']->getKey())
            ->where('billing_year', 2026)
            ->firstOrFail();

        $antwort->assertRedirect(route('portal.abrechnungen.show', ['billingRun' => $folgejahr->getKey()]));

        // Die Stammdaten sind uebernommen, der Vorjahreslauf ist verknuepft.
        self::assertSame(
            (string) $welt['billingRun']->getKey(),
            (string) $folgejahr->getAttribute('previous_billing_run_id'),
        );
        self::assertSame(BillingRunStatus::DRAFT, $folgejahr->getAttribute('status'));

        // Ein zweiter Aufruf legt keinen zweiten Lauf an.
        $this->actingAs($welt['user'])->get($url);

        self::assertSame(
            1,
            BillingRun::query()
                ->where('property_id', $welt['property']->getKey())
                ->where('billing_year', 2026)
                ->count(),
        );
    }
}
