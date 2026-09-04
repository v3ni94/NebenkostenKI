<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Application\Payment\CalculatePrice;
use App\Application\Payment\Exceptions\CustomerAddressMissingException;
use App\Application\Payment\Exceptions\OperatorMasterdataMissingException;
use App\Application\Payment\InvoiceNumberSequence;
use App\Application\Payment\IssueOperatorInvoice;
use App\Application\Payment\OperatorInvoiceBlocker;
use App\Enums\InvoiceStatus;
use App\Models\BillingRun;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Models\Payment;
use App\Services\Storage\ArtifactStorage;
use Illuminate\Support\Carbon;
use Tests\Feature\Pdf\PdfTextExtractor;

/**
 * Leistungsrechnung der Hausverwaltung Mueller GmbH (Abschnitt 15.2).
 */
final class OperatorInvoiceTest extends PaymentTestCase
{
    /**
     * @return array{lauf: BillingRun, zahlung: Payment}
     */
    private function bezahlt(int $anzahl = 3): array
    {
        $daten = $this->vorschaubereiterLauf($anzahl);
        $zahlung = $this->bezahlterLauf($daten['billingRun'], $anzahl * 2490, $anzahl);

        $daten['organization']->forceFill([
            'billing_name' => 'Beispiel Vermietung GmbH',
            'billing_address_line' => 'Beispielweg 5',
            'billing_postal_code' => '40789',
            'billing_city' => 'Monheim am Rhein',
            'vat_id' => 'DE000000001',
        ])->save();

        return ['lauf' => BillingRun::query()->findOrFail($daten['billingRun']->getKey()), 'zahlung' => $zahlung];
    }

    public function test_die_rechnung_weist_netto_steuer_und_brutto_getrennt_aus(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $vorgang = $this->bezahlt(3);

        $preis = app(CalculatePrice::class)->estimate(3);
        $rechnung = app(IssueOperatorInvoice::class)($vorgang['lauf'], $vorgang['zahlung'], $preis);

        self::assertSame(7470, (int) $rechnung->getAttribute('gross_cent'));
        // 3 mal 20,92 EUR netto, Steuer als Differenz zum Brutto.
        self::assertSame(6276, (int) $rechnung->getAttribute('net_cent'));
        self::assertSame(1194, (int) $rechnung->getAttribute('tax_cent'));
        self::assertSame('19.0000', (string) $rechnung->getAttribute('tax_rate_percent'));
        self::assertSame(InvoiceStatus::BEZAHLT, $rechnung->getAttribute('status'));
        self::assertSame('Stripe Checkout', (string) $rechnung->getAttribute('payment_method'));
        self::assertNotNull($rechnung->getAttribute('payment_reference'));
    }

    public function test_rechnungsdatum_und_nummernkreis_folgen_dem_deutschen_kalendertag(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $vorgang = $this->bezahlt(1);

        // Zahlung am 01.01.2027 um 00:30 Uhr deutscher Zeit, in UTC noch der
        // 31.12.2026. Rechnungsdatum und Nummernkreis muessen 2027 tragen.
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:30:00', 'UTC'));

        try {
            self::assertSame('Europe/Berlin', config('app.timezone'));

            $rechnung = app(IssueOperatorInvoice::class)(
                $vorgang['lauf'],
                $vorgang['zahlung'],
                app(CalculatePrice::class)->estimate(1),
            );
        } finally {
            Carbon::setTestNow();
        }

        self::assertSame('2027-01-01', Carbon::parse((string) $rechnung->getAttribute('issued_on'))->format('Y-m-d'));
        self::assertSame('2027-01-01', Carbon::parse((string) $rechnung->getAttribute('service_date'))->format('Y-m-d'));
        self::assertStringStartsWith('NK-2027-', (string) $rechnung->getAttribute('number'));
        self::assertSame(1, app(InvoiceNumberSequence::class)->lastValue(2027));
        self::assertSame(0, app(InvoiceNumberSequence::class)->lastValue(2026));
    }

    public function test_rechnungsdatum_und_nummernkreis_folgen_auch_bei_utc_als_anwendungszeitzone_dem_deutschen_kalendertag(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $vorgang = $this->bezahlt(1);

        // Die fachliche Zeitzone haengt nicht an app.timezone. Laeuft die
        // Anwendung in UTC, muss eine Zahlung um 00:30 Uhr deutscher Zeit am
        // 01.01.2027 trotzdem Rechnungsdatum und Nummernkreis 2027 erhalten.
        $zeitzone = date_default_timezone_get();
        config()->set('app.timezone', 'UTC');
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:30:00', 'UTC'));

        try {
            $rechnung = app(IssueOperatorInvoice::class)(
                $vorgang['lauf'],
                $vorgang['zahlung'],
                app(CalculatePrice::class)->estimate(1),
            );
        } finally {
            Carbon::setTestNow();
            date_default_timezone_set($zeitzone);
            config()->set('app.timezone', $zeitzone);
        }

        self::assertSame('2027-01-01', Carbon::parse((string) $rechnung->getAttribute('issued_on'))->format('Y-m-d'));
        self::assertStringStartsWith('NK-2027-', (string) $rechnung->getAttribute('number'));
        self::assertSame(1, app(InvoiceNumberSequence::class)->lastValue(2027));
    }

    public function test_ohne_rechnungsanschrift_des_kunden_wird_keine_rechnung_erzeugt(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $vorgang = $this->bezahlt(1);

        // Der Kunde hat die Anschrift nach dem Checkout im Konto geleert.
        Organization::query()
            ->whereKey($vorgang['lauf']->getAttribute('organization_id'))
            ->update(['billing_address_line' => null, 'billing_postal_code' => null, 'billing_city' => null]);

        try {
            app(IssueOperatorInvoice::class)(
                $vorgang['lauf'],
                $vorgang['zahlung'],
                app(CalculatePrice::class)->estimate(1),
            );
            self::fail('Ohne Anschrift darf keine Rechnung entstehen.');
        } catch (CustomerAddressMissingException $ausnahme) {
            self::assertStringContainsString('Rechnungsanschrift', $ausnahme->getMessage());
        }

        self::assertSame(0, Invoice::query()->count());
        self::assertSame(0, app(InvoiceNumberSequence::class)->lastValue());
    }

    public function test_die_rechnungsposition_nennt_leistung_objekt_und_zeitraum(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $vorgang = $this->bezahlt(3);

        $rechnung = app(IssueOperatorInvoice::class)(
            $vorgang['lauf'],
            $vorgang['zahlung'],
            app(CalculatePrice::class)->estimate(3),
        );

        /** @var InvoiceItem $position */
        $position = InvoiceItem::query()->where('invoice_id', $rechnung->getKey())->firstOrFail();

        self::assertStringContainsString('Erstellung Betriebskostenabrechnung', (string) $position->getAttribute('description'));
        self::assertStringContainsString('01.01.2025 bis 31.12.2025', (string) $position->getAttribute('description'));
        self::assertSame('3.0000', (string) $position->getAttribute('quantity'));
        self::assertSame(2092, (int) $position->getAttribute('unit_price_net_cent'));
        self::assertSame(3 * 2092, (int) $position->getAttribute('net_cent'));
        self::assertSame(7470, (int) $position->getAttribute('gross_cent'));
    }

    public function test_die_rechnungsanschrift_stammt_aus_dem_kundenkonto(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $vorgang = $this->bezahlt(1);

        $rechnung = app(IssueOperatorInvoice::class)(
            $vorgang['lauf'],
            $vorgang['zahlung'],
            app(CalculatePrice::class)->estimate(1),
        );

        self::assertSame('Beispiel Vermietung GmbH', (string) $rechnung->getAttribute('customer_name'));
        self::assertSame('Beispielweg 5', (string) $rechnung->getAttribute('customer_address_line'));
        self::assertSame('40789', (string) $rechnung->getAttribute('customer_postal_code'));
        self::assertSame('DE000000001', (string) $rechnung->getAttribute('customer_vat_id'));
    }

    public function test_das_rechnungs_pdf_wird_erzeugt_und_mit_sha256_belegt(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $vorgang = $this->bezahlt(2);

        $rechnung = app(IssueOperatorInvoice::class)(
            $vorgang['lauf'],
            $vorgang['zahlung'],
            app(CalculatePrice::class)->estimate(2),
        );

        /** @var GeneratedDocument $beleg */
        $beleg = GeneratedDocument::query()->where('invoice_id', $rechnung->getKey())->firstOrFail();

        $inhalt = app(ArtifactStorage::class)->get((string) $beleg->getAttribute('storage_path'));

        self::assertIsString($inhalt);
        self::assertStringStartsWith('%PDF-', $inhalt);
        self::assertSame(hash('sha256', $inhalt), (string) $rechnung->getAttribute('pdf_sha256'));

        $text = PdfTextExtractor::text($inhalt);

        self::assertStringContainsString((string) $rechnung->getAttribute('number'), $text);
        self::assertStringContainsString('Hausverwaltung', $text);
    }

    public function test_fehlende_pflichtangaben_blockieren_die_erzeugung(): void
    {
        $vorgang = $this->bezahlt(1);

        $this->expectException(OperatorMasterdataMissingException::class);

        try {
            app(IssueOperatorInvoice::class)(
                $vorgang['lauf'],
                $vorgang['zahlung'],
                app(CalculatePrice::class)->estimate(1),
            );
        } finally {
            self::assertSame(0, Invoice::query()->count());
        }
    }

    public function test_nicht_bestaetigte_stammdaten_blockieren_die_erzeugung(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        config()->set('smartabrechnen.operator.masterdata_confirmed', false);

        $vorgang = $this->bezahlt(1);

        self::assertTrue(app(OperatorInvoiceBlocker::class)->isBlocked());
        self::assertSame([], app(OperatorInvoiceBlocker::class)->missingFields());

        $this->expectException(OperatorMasterdataMissingException::class);

        app(IssueOperatorInvoice::class)(
            $vorgang['lauf'],
            $vorgang['zahlung'],
            app(CalculatePrice::class)->estimate(1),
        );
    }

    public function test_die_vorschau_rendert_den_sichtbaren_platzhalter(): void
    {
        $vorgang = $this->bezahlt(1);

        $html = app(IssueOperatorInvoice::class)->placeholderHtml(
            $vorgang['lauf'],
            app(CalculatePrice::class)->estimate(1),
        );

        $platzhalter = config('smartabrechnen.operator.placeholder_text');

        self::assertIsString($platzhalter);
        self::assertStringContainsString($platzhalter, $html);

        // Es wird keine Nummer verbraucht und keine Rechnung geschrieben.
        self::assertSame(0, Invoice::query()->count());
        self::assertSame(0, app(InvoiceNumberSequence::class)->lastValue());
    }

    public function test_der_blockerzustand_ist_fuer_den_adminbereich_abfragbar(): void
    {
        $zustand = app(OperatorInvoiceBlocker::class)->state();

        self::assertTrue($zustand['blockiert']);
        self::assertFalse($zustand['stammdaten_bestaetigt']);
        self::assertContains('Steuernummer', $zustand['fehlende_angaben']);
        self::assertContains('Umsatzsteuer-Identifikationsnummer', $zustand['fehlende_angaben']);
        self::assertContains('IBAN', $zustand['fehlende_angaben']);
        self::assertContains('BIC', $zustand['fehlende_angaben']);
        self::assertStringContainsString('blockiert', $zustand['hinweis']);

        $this->bestaetigteBetreiberstammdaten();

        $bestaetigt = app(OperatorInvoiceBlocker::class)->state();

        self::assertFalse($bestaetigt['blockiert']);
        self::assertSame([], $bestaetigt['fehlende_angaben']);
    }

    public function test_ein_storno_erzeugt_eine_eigene_rechnung_mit_referenz(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $vorgang = $this->bezahlt(2);

        $original = app(IssueOperatorInvoice::class)(
            $vorgang['lauf'],
            $vorgang['zahlung'],
            app(CalculatePrice::class)->estimate(2),
        );

        $originalNummer = (string) $original->getAttribute('number');
        $originalHash = (string) $original->getAttribute('pdf_sha256');
        $originalBrutto = (int) $original->getAttribute('gross_cent');

        $storno = app(IssueOperatorInvoice::class)->cancel($original, 'Korrektur nach Rücksprache mit dem Kunden.');

        self::assertNotSame($originalNummer, (string) $storno->getAttribute('number'));
        self::assertSame((string) $original->getKey(), (string) $storno->getAttribute('cancels_invoice_id'));
        self::assertSame(InvoiceStatus::STORNORECHNUNG, $storno->getAttribute('status'));
        self::assertSame(-1 * $originalBrutto, (int) $storno->getAttribute('gross_cent'));
        self::assertSame(
            (int) $storno->getAttribute('net_cent') + (int) $storno->getAttribute('tax_cent'),
            (int) $storno->getAttribute('gross_cent'),
        );

        // Die Ursprungsrechnung wird nicht ueberschrieben.
        /** @var Invoice $unveraendert */
        $unveraendert = Invoice::query()->findOrFail($original->getKey());

        self::assertSame($originalNummer, (string) $unveraendert->getAttribute('number'));
        self::assertSame($originalBrutto, (int) $unveraendert->getAttribute('gross_cent'));
        self::assertSame($originalHash, (string) $unveraendert->getAttribute('pdf_sha256'));
        self::assertSame(InvoiceStatus::STORNIERT, $unveraendert->getAttribute('status'));
    }

    public function test_das_storno_pdf_nennt_die_stornierte_rechnung(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $vorgang = $this->bezahlt(1);

        $original = app(IssueOperatorInvoice::class)(
            $vorgang['lauf'],
            $vorgang['zahlung'],
            app(CalculatePrice::class)->estimate(1),
        );

        $storno = app(IssueOperatorInvoice::class)->cancel($original, 'Storno aus kaufmännischen Gründen.');

        /** @var GeneratedDocument $beleg */
        $beleg = GeneratedDocument::query()->where('invoice_id', $storno->getKey())->firstOrFail();

        $inhalt = app(ArtifactStorage::class)->get((string) $beleg->getAttribute('storage_path'));

        self::assertIsString($inhalt);

        $text = PdfTextExtractor::text($inhalt);

        self::assertStringContainsString((string) $original->getAttribute('number'), $text);
        self::assertStringContainsString((string) $storno->getAttribute('number'), $text);
    }

    public function test_ein_zweites_storno_erzeugt_keine_weitere_rechnung(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $vorgang = $this->bezahlt(1);

        $original = app(IssueOperatorInvoice::class)(
            $vorgang['lauf'],
            $vorgang['zahlung'],
            app(CalculatePrice::class)->estimate(1),
        );

        $erstes = app(IssueOperatorInvoice::class)->cancel($original, 'Storno.');
        $zweites = app(IssueOperatorInvoice::class)->cancel($original, 'Storno.');

        self::assertSame((string) $erstes->getKey(), (string) $zweites->getKey());
        self::assertSame(2, Invoice::query()->count());
    }

    public function test_der_grundpreis_wird_als_eigene_position_ausgewiesen(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        config()->set('smartabrechnen.pricing.base_gross_cent', 1000);

        $vorgang = $this->bezahlt(2);

        $rechnung = app(IssueOperatorInvoice::class)(
            $vorgang['lauf'],
            $vorgang['zahlung'],
            app(CalculatePrice::class)->estimate(2),
        );

        $positionen = InvoiceItem::query()
            ->where('invoice_id', $rechnung->getKey())
            ->orderBy('position')
            ->get();

        self::assertCount(2, $positionen);
        self::assertSame(1000, (int) $positionen[1]->getAttribute('gross_cent'));
        self::assertSame(
            (int) $rechnung->getAttribute('gross_cent'),
            (int) $positionen[0]->getAttribute('gross_cent') + (int) $positionen[1]->getAttribute('gross_cent'),
        );
    }
}
