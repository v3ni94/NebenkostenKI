<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Application\Documents\FailDocument;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Enums\EmailSuppressionReason;
use App\Mail\DokumentverarbeitungAbgeschlossenMail;
use App\Mail\SuppressionGuard;
use App\Mail\VerarbeitungsfehlerMail;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\EmailMessage;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Review\ReviewTestCase;

/**
 * Verdrahtung der Statusmails zur Dokumentverarbeitung (Masterprompt 16).
 *
 * Die Nachrichten haengen am Modellereignis des Dokuments und gehen genau
 * dann, wenn das letzte Dokument eines Laufs einen Endzustand erreicht. Der
 * Weg ist derselbe wie in der Pipeline: Statuswechsel per save() beziehungsweise
 * ueber den Use Case FailDocument.
 */
final class StatusmailVerdrahtungTest extends ReviewTestCase
{
    /**
     * @return array{lauf: BillingRun, erstes: Document, zweites: Document, email: string}
     */
    private function laufMitZweiUnterlagen(): array
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property'], [
            'created_by_user_id' => $mandant['user']->getKey(),
        ]);

        $erstes = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 01', [
            'processing_status' => DocumentProcessingStatus::EXTRAKTION,
        ]);
        $zweites = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 02', [
            'processing_status' => DocumentProcessingStatus::EXTRAKTION,
        ]);

        return [
            'lauf' => $lauf,
            'erstes' => $erstes,
            'zweites' => $zweites,
            'email' => (string) $mandant['user']->email,
        ];
    }

    public function test_der_abschluss_der_letzten_unterlage_versendet_die_abschlussmail_genau_einmal(): void
    {
        Mail::fake();
        $welt = $this->laufMitZweiUnterlagen();

        $welt['erstes']->forceFill(['processing_status' => DocumentProcessingStatus::ABGESCHLOSSEN])->save();

        Mail::assertNothingSent();

        $welt['zweites']->forceFill(['processing_status' => DocumentProcessingStatus::ABGESCHLOSSEN])->save();

        Mail::assertSent(DokumentverarbeitungAbgeschlossenMail::class, function (
            DokumentverarbeitungAbgeschlossenMail $mail
        ) use ($welt): bool {
            return $mail->hasTo($welt['email']);
        });
        Mail::assertSentCount(1);

        // Eine weitere Aenderung ohne Statuswechsel loest keinen zweiten Versand aus.
        $welt['zweites']->forceFill(['page_count' => 7])->save();

        Mail::assertSentCount(1);

        self::assertSame(
            1,
            EmailMessage::query()
                ->where('billing_run_id', $welt['lauf']->getKey())
                ->where('template', 'dokumentverarbeitung-abgeschlossen')
                ->count()
        );
    }

    public function test_ein_endgueltiger_fehler_versendet_die_fehlermeldung_statt_der_abschlussmail(): void
    {
        Mail::fake();
        $welt = $this->laufMitZweiUnterlagen();

        $welt['erstes']->forceFill(['processing_status' => DocumentProcessingStatus::ABGESCHLOSSEN])->save();

        app(FailDocument::class)($welt['zweites'], UploadErrorCode::EXTRAKTION_FEHLGESCHLAGEN);

        Mail::assertSent(VerarbeitungsfehlerMail::class, function (VerarbeitungsfehlerMail $mail) use ($welt): bool {
            return $mail->hasTo($welt['email']);
        });
        Mail::assertNotSent(DokumentverarbeitungAbgeschlossenMail::class);

        /** @var EmailMessage $protokoll */
        $protokoll = EmailMessage::query()
            ->where('billing_run_id', $welt['lauf']->getKey())
            ->where('template', 'verarbeitungsfehler')
            ->firstOrFail();

        self::assertSame(mb_strtolower($welt['email']), $protokoll->getAttribute('recipient_email'));
    }

    public function test_eine_dublette_ist_kein_fehler(): void
    {
        Mail::fake();
        $welt = $this->laufMitZweiUnterlagen();

        $welt['erstes']->forceFill(['processing_status' => DocumentProcessingStatus::ABGESCHLOSSEN])->save();
        $welt['zweites']->forceFill([
            'processing_status' => DocumentProcessingStatus::ABGELEHNT,
            'duplicate_of_document_id' => $welt['erstes']->getKey(),
        ])->save();

        Mail::assertSent(DokumentverarbeitungAbgeschlossenMail::class);
        Mail::assertNotSent(VerarbeitungsfehlerMail::class);
    }

    public function test_die_sperrliste_unterdrueckt_die_abschlussmail_aber_nicht_die_fehlermeldung(): void
    {
        Mail::fake();
        $welt = $this->laufMitZweiUnterlagen();

        app(SuppressionGuard::class)->suppress($welt['email'], EmailSuppressionReason::ABMELDUNG);

        $welt['erstes']->forceFill(['processing_status' => DocumentProcessingStatus::ABGESCHLOSSEN])->save();
        $welt['zweites']->forceFill(['processing_status' => DocumentProcessingStatus::ABGESCHLOSSEN])->save();

        Mail::assertNotSent(DokumentverarbeitungAbgeschlossenMail::class);

        // Erneuter Upload, der endgueltig scheitert: die kritische Nachricht geht trotz Sperre.
        $drittes = $this->dokument($welt['lauf'], DocumentType::RECHNUNG, 'Unterlage 03', [
            'processing_status' => DocumentProcessingStatus::EXTRAKTION,
        ]);

        app(FailDocument::class)($drittes, UploadErrorCode::EXTRAKTION_FEHLGESCHLAGEN);

        Mail::assertSent(VerarbeitungsfehlerMail::class);
    }
}
