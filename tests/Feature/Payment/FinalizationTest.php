<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Application\Payment\Dto\FinalViewBundle;
use App\Application\Payment\Events\BillingRunFinalized;
use App\Application\Payment\Exceptions\FinalizationFailedException;
use App\Application\Payment\FinalizeBillingRun;
use App\Application\Payment\InvoiceNumberSequence;
use App\Enums\BillingRunStatus;
use App\Enums\CalculationSnapshotStatus;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\UnitStatementStatus;
use App\Models\BillingRun;
use App\Models\CalculationSnapshot;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\UnitStatement;
use App\Models\User;
use App\Services\Storage\ArtifactStorage;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Pdf\PdfFixtures;
use Tests\Feature\Pdf\PdfTextExtractor;
use ZipArchive;

/**
 * Schritt 12: Finalisierung (Abschnitt 9 Schritt 12, 14.3, 11.5).
 */
final class FinalizationTest extends PaymentTestCase
{
    /**
     * @return array{
     *     lauf: BillingRun,
     *     snapshot: CalculationSnapshot,
     *     nutzer: User
     * }
     */
    private function bezahlterVorgang(int $abrechnungen = 2, bool $stammdaten = true): array
    {
        if ($stammdaten) {
            $this->bestaetigteBetreiberstammdaten();
        }

        $daten = $this->vorschaubereiterLauf($abrechnungen);
        $this->bezahlterLauf($daten['billingRun'], $abrechnungen * 2490, $abrechnungen);

        $this->bindeFinalDocumentViews(new FinalViewBundle(
            [PdfFixtures::statementView(), PdfFixtures::statementView()],
            [null, null],
            PdfFixtures::ownerOverviewView(),
        ));

        return [
            'lauf' => BillingRun::query()->findOrFail($daten['billingRun']->getKey()),
            'snapshot' => $daten['snapshot'],
            'nutzer' => $daten['user'],
        ];
    }

    public function test_die_finalisierung_erzeugt_wasserzeichenfreie_pdfs_aus_dem_snapshot(): void
    {
        $vorgang = $this->bezahlterVorgang();

        $ergebnis = app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        self::assertGreaterThanOrEqual(3, $ergebnis->documentCount());

        $wasserzeichen = config('smartabrechnen.pdf.watermark_text');
        self::assertIsString($wasserzeichen);

        $ablage = app(ArtifactStorage::class);

        foreach ($ergebnis->documents as $dokument) {
            self::assertSame(GeneratedDocumentVariant::FINAL, $dokument->getAttribute('variant'));
            self::assertSame(GeneratedDocumentStatus::AKTIV, $dokument->getAttribute('status'));
            self::assertSame(64, strlen((string) $dokument->getAttribute('sha256')));
            self::assertSame(
                (string) $vorgang['snapshot']->getKey(),
                (string) $dokument->getAttribute('calculation_snapshot_id'),
            );

            $inhalt = $ablage->get((string) $dokument->getAttribute('storage_path'));

            self::assertIsString($inhalt);
            self::assertStringStartsWith('%PDF-', $inhalt);
            self::assertSame(
                0,
                PdfTextExtractor::occurrences($inhalt, $wasserzeichen),
                'Eine Finalversion darf kein Wasserzeichen tragen.',
            );
            self::assertSame(hash('sha256', $inhalt), (string) $dokument->getAttribute('sha256'));
        }
    }

    public function test_der_bezahlte_berechnungsstand_wird_gesperrt(): void
    {
        $vorgang = $this->bezahlterVorgang();

        self::assertNull($vorgang['snapshot']->getAttribute('locked_at'));

        app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        /** @var CalculationSnapshot $snapshot */
        $snapshot = CalculationSnapshot::query()->findOrFail($vorgang['snapshot']->getKey());

        self::assertNotNull($snapshot->getAttribute('locked_at'));
        self::assertSame(CalculationSnapshotStatus::GESPERRT, $snapshot->getAttribute('status'));
        self::assertTrue($snapshot->isLocked());
    }

    public function test_der_lauf_wird_auf_finalized_gesetzt_und_ist_terminal(): void
    {
        $vorgang = $this->bezahlterVorgang();

        app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());

        self::assertSame(BillingRunStatus::FINALIZED, $lauf->getAttribute('status'));
        self::assertNotNull($lauf->getAttribute('finalized_at'));
    }

    public function test_die_mieterabrechnungen_werden_auf_final_gesetzt(): void
    {
        $vorgang = $this->bezahlterVorgang();

        app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        $stati = UnitStatement::query()
            ->where('billing_run_id', $vorgang['lauf']->getKey())
            ->pluck('status')
            ->all();

        self::assertNotSame([], $stati);

        foreach ($stati as $status) {
            self::assertSame(UnitStatementStatus::FINAL, $status);
        }
    }

    public function test_das_zip_paket_enthaelt_alle_finalen_dateien(): void
    {
        $vorgang = $this->bezahlterVorgang();

        $ergebnis = app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        self::assertNotNull($ergebnis->package);
        self::assertSame(GeneratedDocumentKind::ZIP_PAKET, $ergebnis->package->getAttribute('kind'));

        $inhalt = app(ArtifactStorage::class)->get((string) $ergebnis->package->getAttribute('storage_path'));

        self::assertIsString($inhalt);

        $datei = tempnam(sys_get_temp_dir(), 'sa-test-paket-');
        self::assertIsString($datei);
        file_put_contents($datei, $inhalt);

        $zip = new ZipArchive;
        self::assertTrue($zip->open($datei) === true);

        // Zwei Mieterabrechnungen, zwei Anlagen nach Paragraf 35a,
        // Eigentuemeruebersicht und die Rechnung.
        self::assertSame($ergebnis->documentCount() + 1, $zip->numFiles);

        $namen = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $namen[] = (string) $zip->getNameIndex($index);
        }

        $zip->close();
        @unlink($datei);

        $zusammen = implode(' ', $namen);

        self::assertStringContainsString('.pdf', $zusammen);
        self::assertStringContainsString('Rechnung', $zusammen);
    }

    public function test_die_rechnung_wird_mit_lueckenloser_nummer_erzeugt(): void
    {
        $vorgang = $this->bezahlterVorgang();

        $ergebnis = app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        self::assertNotNull($ergebnis->invoice);
        self::assertMatchesRegularExpression(
            '/^NK-\d{4}-\d{6}$/',
            (string) $ergebnis->invoice->getAttribute('number'),
        );
        self::assertSame(2 * 2490, (int) $ergebnis->invoice->getAttribute('gross_cent'));
        self::assertSame(
            (int) $ergebnis->invoice->getAttribute('gross_cent'),
            (int) $ergebnis->invoice->getAttribute('net_cent') + (int) $ergebnis->invoice->getAttribute('tax_cent'),
        );
        self::assertSame(64, strlen((string) $ergebnis->invoice->getAttribute('pdf_sha256')));
    }

    public function test_fehlende_steuerdaten_blockieren_die_produktive_rechnung(): void
    {
        $vorgang = $this->bezahlterVorgang(stammdaten: false);

        $ergebnis = app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        self::assertNull($ergebnis->invoice);
        self::assertTrue($ergebnis->invoiceIsBlocked());
        self::assertContains('Steuernummer', $ergebnis->invoiceBlockers);
        self::assertContains('IBAN', $ergebnis->invoiceBlockers);
        self::assertSame(0, Invoice::query()->count());

        // Keine Rechnungsnummer wird verbraucht.
        self::assertSame(0, app(InvoiceNumberSequence::class)->lastValue());

        // Die bezahlten Abrechnungen entstehen dennoch vollstaendig.
        self::assertGreaterThan(0, $ergebnis->documentCount());
        self::assertSame(BillingRunStatus::FINALIZED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
    }

    public function test_ohne_bestaetigte_zahlung_wird_nichts_erzeugt(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $daten = $this->vorschaubereiterLauf(2);

        $this->bindeFinalDocumentViews(new FinalViewBundle([PdfFixtures::statementView()], [null]));

        $this->expectException(FinalizationFailedException::class);

        try {
            app(FinalizeBillingRun::class)($daten['billingRun'], $daten['user']);
        } finally {
            self::assertSame(0, GeneratedDocument::query()->count());
            self::assertSame(0, Invoice::query()->count());
        }
    }

    public function test_ohne_aufbereitung_des_snapshots_bricht_die_finalisierung_ab(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $daten = $this->vorschaubereiterLauf(2);
        $this->bezahlterLauf($daten['billingRun'], 4980, 2);

        // Keine Bindung von FinalDocumentViews: es darf kein Ersatzwert
        // gebildet werden.
        $this->expectException(FinalizationFailedException::class);

        try {
            app(FinalizeBillingRun::class)($daten['billingRun'], $daten['user']);
        } finally {
            self::assertSame(0, GeneratedDocument::query()->count());
            self::assertSame(BillingRunStatus::FAILED, BillingRun::query()
                ->findOrFail($daten['billingRun']->getKey())
                ->getAttribute('status'));
        }
    }

    public function test_eine_korrektur_erzeugt_eine_neue_version_und_setzt_die_alte_auf_ersetzt(): void
    {
        $vorgang = $this->bezahlterVorgang();

        $erste = app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        $alteKennungen = array_map(
            static fn (GeneratedDocument $dokument): string => (string) $dokument->getKey(),
            $erste->documents,
        );
        $alteHashes = array_map(
            static fn (GeneratedDocument $dokument): string => (string) $dokument->getAttribute('sha256'),
            $erste->documents,
        );

        // Der Lauf ist finalisiert. Eine Korrektur setzt ihn erneut in die
        // Finalisierung; die Statusmaschine laesst das nur bei bestaetigter
        // Zahlung zu.
        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());
        $lauf->forceFill(['status' => BillingRunStatus::PAID])->save();

        $zweite = app(FinalizeBillingRun::class)($lauf, $vorgang['nutzer']);

        foreach ($alteKennungen as $index => $kennung) {
            /** @var GeneratedDocument $alt */
            $alt = GeneratedDocument::query()->findOrFail($kennung);

            self::assertSame(GeneratedDocumentStatus::ERSETZT, $alt->getAttribute('status'));
            self::assertNotNull($alt->getAttribute('replaced_by_document_id'));

            // Die alte Datei wurde nicht ueberschrieben.
            self::assertSame($alteHashes[$index], (string) $alt->getAttribute('sha256'));

            $inhalt = app(ArtifactStorage::class)->get((string) $alt->getAttribute('storage_path'));
            self::assertIsString($inhalt);
            self::assertSame($alteHashes[$index], hash('sha256', $inhalt));
        }

        foreach ($zweite->documents as $neu) {
            self::assertSame(GeneratedDocumentStatus::AKTIV, $neu->getAttribute('status'));
            self::assertNotContains((string) $neu->getKey(), $alteKennungen);
        }
    }

    public function test_eine_zweite_finalisierung_erzeugt_keine_zweite_rechnung(): void
    {
        $vorgang = $this->bezahlterVorgang();

        $erste = app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());
        $lauf->forceFill(['status' => BillingRunStatus::PAID])->save();

        $zweite = app(FinalizeBillingRun::class)($lauf, $vorgang['nutzer']);

        self::assertNotNull($erste->invoice);
        self::assertNotNull($zweite->invoice);
        self::assertSame(
            (string) $erste->invoice->getAttribute('number'),
            (string) $zweite->invoice->getAttribute('number'),
        );
        self::assertSame(1, Invoice::query()->whereNull('cancels_invoice_id')->count());
    }

    public function test_eine_zweite_finalisierung_laesst_den_rechnungsbeleg_aktiv(): void
    {
        $vorgang = $this->bezahlterVorgang();

        $erste = app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        self::assertNotNull($erste->invoice);

        /** @var GeneratedDocument $beleg */
        $beleg = GeneratedDocument::query()->where('invoice_id', $erste->invoice->getKey())->firstOrFail();

        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());
        $lauf->forceFill(['status' => BillingRunStatus::PAID])->save();

        app(FinalizeBillingRun::class)($lauf, $vorgang['nutzer']);

        // Die Rechnung wird nicht neu erzeugt und darf deshalb nicht als
        // ersetzt gelten: sie ist ein aufzubewahrender Beleg.
        $beleg->refresh();

        self::assertSame(GeneratedDocumentStatus::AKTIV, $beleg->getAttribute('status'));
        self::assertNull($beleg->getAttribute('replaced_by_document_id'));
        self::assertSame(GeneratedDocumentKind::HVM_RECHNUNG, $beleg->getAttribute('kind'));
    }

    public function test_die_rechnung_stellt_den_bezahlten_betrag_und_nicht_den_neuen_preis(): void
    {
        $vorgang = $this->bezahlterVorgang(2);

        // Der Betreiber aendert den Preis nach der Zahlung. Die Rechnung muss
        // weiterhin den bezahlten Betrag ausweisen.
        config()->set('smartabrechnen.pricing.per_statement_gross_cent', 2990);

        $ergebnis = app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        self::assertNotNull($ergebnis->invoice);
        self::assertSame(4980, (int) $ergebnis->invoice->getAttribute('gross_cent'));
        self::assertSame(
            (int) $ergebnis->invoice->getAttribute('gross_cent'),
            (int) $ergebnis->invoice->getAttribute('net_cent') + (int) $ergebnis->invoice->getAttribute('tax_cent'),
        );
    }

    public function test_das_ereignis_fuer_die_bestaetigungsmail_wird_ausgeloest(): void
    {
        Event::fake([BillingRunFinalized::class]);

        $vorgang = $this->bezahlterVorgang();

        app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        Event::assertDispatched(
            BillingRunFinalized::class,
            function (BillingRunFinalized $ereignis) use ($vorgang): bool {
                return (string) $ereignis->billingRun->getKey() === (string) $vorgang['lauf']->getKey()
                    && $ereignis->generatedDocumentIds !== []
                    && $ereignis->packageDocumentId !== null
                    && $ereignis->invoiceBlocked === false;
            },
        );
    }

    public function test_das_ereignis_meldet_einen_blockierten_rechnungslauf(): void
    {
        Event::fake([BillingRunFinalized::class]);

        $vorgang = $this->bezahlterVorgang(stammdaten: false);

        app(FinalizeBillingRun::class)($vorgang['lauf'], $vorgang['nutzer']);

        Event::assertDispatched(
            BillingRunFinalized::class,
            static fn (BillingRunFinalized $ereignis): bool => $ereignis->invoiceBlocked === true
                && $ereignis->invoice === null,
        );
    }
}
