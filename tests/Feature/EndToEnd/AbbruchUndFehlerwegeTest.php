<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use App\Application\Payment\FinalizeBillingRun;
use App\Enums\BillingRunStatus;
use App\Enums\DeletionStatus;
use App\Enums\DocumentProcessingStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\PaymentStatus;
use App\Enums\ProcessingJobStatus;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\EmailMessage;
use App\Models\ExtractedField;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Prepayment;
use App\Models\ProcessingJob;
use App\Models\TemporaryUpload;
use App\Models\WebhookEvent;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Support\Facades\Storage;

/**
 * Abbruch- und Fehlerwege nach Abschnitt 23.4.
 *
 * Jeder Weg endet in einem sauberen Zustand: keine halbfertige Abrechnung,
 * keine ersatzweise gebildeten Werte, keine liegen gebliebene Originaldatei
 * und keine Rechnung ohne bestaetigte Zahlung.
 */
final class AbbruchUndFehlerwegeTest extends EndToEndTestCase
{
    public function test_ohne_erreichbare_ki_anbindung_bleibt_keine_originaldatei_liegen(): void
    {
        // Die KI-Anbindung wird ausdruecklich NICHT gebunden. Das ist der
        // Zustand "Provider nicht erreichbar" aus Sicht der Pipeline.
        $welt = $this->konto();

        $upload = $this->ladeHoch(
            $welt['user'],
            $welt['billingRun'],
            $this->beispielPdf(2),
            'hausgeldabrechnung.pdf'
        );

        $praefix = (string) $upload->getAttribute('storage_key');

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        self::assertNotSame(
            DocumentProcessingStatus::ABGESCHLOSSEN,
            $dokument->getAttribute('processing_status'),
        );
        self::assertSame(0, ExtractedField::query()->count());

        // Es wird nichts geraten und keine Kostenposition erfunden.
        $this->actingAs($welt['user'])
            ->post(route('portal.pruefung.zuordnen', ['billingRun' => $welt['billingRun']->getKey()]))
            ->assertRedirect();

        self::assertSame(0, CostItem::query()->count());

        // Der Teiljob bleibt zunaechst wiederholbar. Erst nach dem letzten
        // Versuch ist der Fehler endgueltig; dann entfernt der Loeschpfad die
        // Quelldaten sofort (Abschnitt 6.3).
        self::assertSame(
            ProcessingJobStatus::BEREIT,
            ProcessingJob::query()->where('job_type', 'dokument.klassifizieren')->firstOrFail()
                ->getAttribute('status'),
        );

        for ($versuch = 0; $versuch < 4; $versuch++) {
            $this->travel(2)->hours();
            $this->verarbeiteQueue();
        }

        $dokument->refresh();

        self::assertSame(DocumentProcessingStatus::FEHLGESCHLAGEN, $dokument->getAttribute('processing_status'));
        self::assertSame(
            UploadErrorCode::KI_SCHICHT_NICHT_VERFUEGBAR->value,
            (string) $dokument->getAttribute('failure_code'),
        );
        self::assertSame(DeletionStatus::ERFOLGREICH, $dokument->getAttribute('deletion_status'));

        $upload = TemporaryUpload::query()->firstOrFail();

        self::assertTrue($upload->getAttribute('is_tombstone'));
        self::assertNull($upload->getAttribute('storage_key'));
        self::assertFalse(
            Storage::disk(TemporaryUploadStorage::DISK)->exists($praefix.'/original.bin'),
            'Nach dem endgueltigen Fehler darf keine Originaldatei liegen bleiben.'
        );
        self::assertSame(0, ExtractedField::query()->count());
    }

    public function test_eine_unlesbare_datei_wird_abgelehnt_und_hinterlaesst_nichts(): void
    {
        $this->bindeKiSchicht();

        $welt = $this->konto();

        // Technisch unbrauchbarer Inhalt mit PDF-Endung.
        $upload = $this->ladeHoch(
            $welt['user'],
            $welt['billingRun'],
            'Dies ist keine gueltige PDF-Datei, sondern Zufallsinhalt.',
            'unlesbar.pdf'
        );

        $praefix = (string) $upload->getAttribute('storage_key');

        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        self::assertNotSame(
            DocumentProcessingStatus::ABGESCHLOSSEN,
            $dokument->getAttribute('processing_status'),
        );
        self::assertNotNull($dokument->getAttribute('failure_code'));
        self::assertSame(0, ExtractedField::query()->count());
        self::assertFalse(Storage::disk(TemporaryUploadStorage::DISK)->exists($praefix.'/original.bin'));
        self::assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles());

        // Die Statusseite nennt den Sachverhalt, aber keinen Providernamen und
        // keinen technischen Fehlercode.
        $antwort = $this->actingAs($welt['user'])
            ->get(route('portal.pruefung.analyse', ['billingRun' => $welt['billingRun']->getKey()]));

        $antwort->assertOk();
        $antwort->assertDontSee('OpenAI');
        $antwort->assertDontSee('Anthropic');
    }

    public function test_ein_fehlender_pflichtwert_verhindert_die_vorschau_mit_klarer_ursache(): void
    {
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

        // Pflichtwert fehlt: die Vorauszahlungen sind nicht mehr erfasst.
        Prepayment::query()->where('billing_run_id', $welt['billingRun']->getKey())->delete();

        $antwort = $this->actingAs($welt['user'])
            ->from(route('portal.wizard.vorschau', ['billingRun' => $welt['billingRun']->getKey()]))
            ->post(route('portal.wizard.vorschau.erzeugen', [
                'billingRun' => $welt['billingRun']->getKey(),
            ]));

        $antwort->assertSessionHasErrors();

        $fehler = session('errors');

        self::assertNotNull($fehler);
        self::assertStringContainsString('Vorauszahlungen', (string) $fehler->first());

        // Es entsteht keine Vorschau und damit auch kein Zahlungsvorgang.
        self::assertSame(0, GeneratedDocument::query()->count());
        self::assertSame(0, Payment::query()->count());

        // Der Weiterschritt bleibt gesperrt.
        $this->actingAs($welt['user'])
            ->post(route('portal.wizard.vorauszahlungen.weiter', [
                'billingRun' => $welt['billingRun']->getKey(),
            ]))
            ->assertSessionHasErrors('weiter');
    }

    public function test_ein_abgebrochener_zahlungsvorgang_schaltet_nichts_frei(): void
    {
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
        $this->versetzeInVorschaubereit($welt['billingRun'], $welt['user']);

        $this->actingAs($welt['user'])->post(
            route('portal.checkout.store', ['billingRun' => $welt['billingRun']->getKey()]),
            ['sofortige_ausfuehrung' => '1', 'vertragsgrundlagen' => '1']
        )->assertRedirect();

        // Abbruch durch den Nutzer auf der Zahlungsseite.
        $this->actingAs($welt['user'])
            ->delete(route('portal.checkout.destroy', ['billingRun' => $welt['billingRun']->getKey()]))
            ->assertRedirect();

        $lauf = $welt['billingRun']->refresh();

        self::assertSame(BillingRunStatus::PREVIEW_READY, $lauf->getAttribute('status'));
        self::assertNull($lauf->getAttribute('paid_at'));

        $zahlung = Payment::query()->firstOrFail();

        self::assertNotSame(PaymentStatus::BEZAHLT, $zahlung->getAttribute('status'));

        // Weder Finaldokument noch Rechnung noch Bestaetigungsmail.
        self::assertSame(
            0,
            GeneratedDocument::query()
                ->where('variant', GeneratedDocumentVariant::FINAL->value)
                ->count()
        );
        self::assertSame(0, Invoice::query()->count());
        self::assertSame(0, EmailMessage::query()->count());

        // Die Rueckkehrseite des Browsers aendert den Zustand nicht.
        $this->actingAs($welt['user'])
            ->get(route('portal.checkout.abbruch', ['billingRun' => $welt['billingRun']->getKey()]))
            ->assertRedirect();

        self::assertSame(BillingRunStatus::PREVIEW_READY, $welt['billingRun']->refresh()->getAttribute('status'));
    }

    public function test_dieselbe_providerbenachrichtigung_zweimal_finalisiert_nur_einmal(): void
    {
        $welt = $this->fuehreHappyPathAus();

        $dokumenteVorher = GeneratedDocument::query()
            ->where('variant', GeneratedDocumentVariant::FINAL->value)
            ->count();

        // Genau dieselbe Nutzlast, erneut korrekt signiert.
        $this->sendeWebhook($welt['payload'])->assertOk();

        self::assertSame(
            $dokumenteVorher,
            GeneratedDocument::query()
                ->where('variant', GeneratedDocumentVariant::FINAL->value)
                ->count(),
            'Eine wiederholte Benachrichtigung darf keine zweiten Finaldokumente erzeugen.'
        );

        self::assertSame(1, Payment::query()->count());
        self::assertSame(1, Invoice::query()->count());
        self::assertSame(3, EmailMessage::query()->count());
        self::assertSame(BillingRunStatus::FINALIZED, $welt['billingRun']->refresh()->getAttribute('status'));

        // Das Ereignis ist genau einmal verarbeitet.
        self::assertSame(1, WebhookEvent::query()->count());
    }

    public function test_ein_kurzzeitig_gestoerter_artefaktspeicher_laesst_die_finalisierung_wiederholen(): void
    {
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
        $this->versetzeInVorschaubereit($welt['billingRun'], $welt['user']);

        $this->actingAs($welt['user'])->post(
            route('portal.checkout.store', ['billingRun' => $welt['billingRun']->getKey()]),
            ['sofortige_ausfuehrung' => '1', 'vertragsgrundlagen' => '1']
        )->assertRedirect();

        $zahlung = Payment::query()->where('billing_run_id', $welt['billingRun']->getKey())->firstOrFail();
        $payload = $this->erfolgsnutzlast($zahlung);

        // Der Artefaktspeicher ist kurzzeitig nicht erreichbar.
        $this->stoereArtefaktspeicher();

        $this->sendeWebhook($payload);

        $lauf = $welt['billingRun']->refresh();

        // Die Zahlung bleibt bestaetigt, der Lauf ist gescheitert und es liegt
        // kein halbfertiges Finaldokument vor.
        self::assertSame(PaymentStatus::BEZAHLT, $zahlung->refresh()->getAttribute('status'));
        self::assertSame(BillingRunStatus::FAILED, $lauf->getAttribute('status'));
        self::assertSame('FINALISIERUNG_FEHLGESCHLAGEN', (string) $lauf->getAttribute('failure_code'));
        self::assertSame(
            0,
            GeneratedDocument::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('variant', GeneratedDocumentVariant::FINAL->value)
                ->count()
        );

        // Der Speicher ist wieder erreichbar. Die Finalisierung wird
        // wiederholt und laeuft vollstaendig durch.
        $this->stelleArtefaktspeicherWiederHer();

        app(FinalizeBillingRun::class)($lauf, $welt['user']);

        $lauf->refresh();

        self::assertSame(BillingRunStatus::FINALIZED, $lauf->getAttribute('status'));
        self::assertGreaterThanOrEqual(
            3,
            GeneratedDocument::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('variant', GeneratedDocumentVariant::FINAL->value)
                ->count()
        );
        self::assertSame(1, Invoice::query()->count());
    }
}
