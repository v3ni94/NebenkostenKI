<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use App\Enums\BillingRunStatus;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentVariant;
use App\Mail\HvmRechnungVerfuegbarMail;
use App\Mail\ZahlungBestaetigtMail;
use App\Models\AuditLog;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use Illuminate\Support\Facades\Mail;

/**
 * Fehlende Pflichtangaben des Betreibers (Abschnitt 15.2).
 *
 * Der Kunde hat bezahlt. Die Abrechnungen werden deshalb vollstaendig erzeugt
 * und bereitgestellt, es wird aber keine Rechnungsnummer verbraucht. Die
 * Zahlungsbestaetigung geht ohne Rechnungsanhang hinaus und weist sachlich
 * darauf hin, wo die Rechnung zu finden ist.
 */
final class BlockierteRechnungTest extends EndToEndTestCase
{
    public function test_ohne_betreiberstammdaten_wird_bezahlt_geliefert_aber_keine_rechnung_erzeugt(): void
    {
        Mail::fake();

        $this->bindeKiSchicht();

        $welt = $this->konto();
        $this->ladeUnterlagenHochUndWerteAus($welt['user'], $welt['billingRun']);
        $this->pruefeKosten($welt['user'], $welt['billingRun']);
        $this->erfasseVerteilungUndPruefbericht(
            $welt['user'],
            $welt['billingRun'],
            $welt['unit'],
            $welt['tenancy'],
        );
        $this->erzeugeUndBestaetigeVorschau($welt['user'], $welt['billingRun']);

        // Die Pflichtangaben des Betreibers fehlen.
        config([
            'smartabrechnen.operator.tax_id' => null,
            'smartabrechnen.operator.vat_id' => null,
            'smartabrechnen.operator.iban' => null,
            'smartabrechnen.operator.bic' => null,
            'smartabrechnen.operator.masterdata_confirmed' => false,
        ]);

        $this->zahleUndFinalisiere($welt['user'], $welt['billingRun']);

        $lauf = $welt['billingRun']->refresh();

        // Der Lauf ist finalisiert und die Abrechnungen liegen bereit.
        self::assertSame(BillingRunStatus::FINALIZED, $lauf->getAttribute('status'));
        self::assertGreaterThanOrEqual(
            2,
            GeneratedDocument::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('variant', GeneratedDocumentVariant::FINAL->value)
                ->count()
        );

        // Es ist keine Rechnung festgeschrieben und keine Nummer verbraucht.
        self::assertSame(0, Invoice::query()->count());
        self::assertSame(
            0,
            GeneratedDocument::query()
                ->where('kind', GeneratedDocumentKind::HVM_RECHNUNG->value)
                ->count()
        );

        // Der Blockerzustand ist protokolliert.
        self::assertTrue(AuditLog::query()->where('action', 'invoice.blocked')->exists());

        // Die Zahlungsbestaetigung geht dennoch hinaus, ohne Anhang und mit
        // dem sachlichen Hinweis auf den Abruf im Konto.
        Mail::assertSent(ZahlungBestaetigtMail::class, function (ZahlungBestaetigtMail $mail): bool {
            self::assertSame([], $mail->anhangDokumente());
            self::assertFalse($mail->daten()['rechnungAngehaengt']);
            self::assertStringContainsString(
                'Ihre Rechnung finden Sie in Ihrem Konto zum Abruf.',
                $mail->render(),
            );

            return true;
        });

        // Ohne Rechnung gibt es auch keine Rechnungsmail.
        Mail::assertNotSent(HvmRechnungVerfuegbarMail::class);
    }
}
