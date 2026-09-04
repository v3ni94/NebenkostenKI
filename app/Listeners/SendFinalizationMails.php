<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Application\Payment\Events\BillingRunFinalized;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Mail\FinalabrechnungenVerfuegbarMail;
use App\Mail\HvmRechnungVerfuegbarMail;
use App\Mail\MailDispatcher;
use App\Mail\SignedDownloadLink;
use App\Mail\ZahlungBestaetigtMail;
use App\Models\BillingRun;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Versand der Bestaetigungsmails nach der Finalisierung (Masterprompt 16).
 *
 * VERBINDLICHE REGELN
 *
 *  1. KEINE MIETERABRECHNUNG ALS ANHANG. Die finalen Abrechnungen erreichen
 *     den Nutzer ausschliesslich ueber einen zeitlich begrenzten,
 *     kontogebundenen und signierten Downloadlink auf die Route
 *     portal.downloads.signed. Der Link ersetzt die Anmeldung nicht; die
 *     Zielroute prueft weiterhin Anmeldung, bestaetigte Adresse und
 *     Mandantenzugehoerigkeit.
 *  2. Die Leistungsrechnung der Hausverwaltung Mueller GmbH darf angehaengt
 *     werden. Sie enthaelt keine Mieterdaten.
 *  3. IST DIE RECHNUNG BLOCKIERT (fehlende Steuer- oder Bankdaten, Abschnitt
 *     15.2), wird die Zahlungsbestaetigung dennoch versendet, aber ohne
 *     Rechnungsanhang. Der Nutzer hat bezahlt und muss den Zahlungsstand
 *     kennen. Den sachlichen Hinweis auf die spaetere Rechnung traegt die
 *     Vorlage der Zahlungsbestaetigung selbst, sobald kein Anhang beiliegt;
 *     der Listener erfindet keinen eigenen Text und protokolliert den
 *     Blockerzustand zusaetzlich technisch.
 *  4. Ein Versandfehler darf die abgeschlossene Finalisierung nicht
 *     zurueckdrehen. Der Zahlungsvorgang ist beendet, die Dokumente liegen
 *     bereit. Ein Fehler wird deshalb protokolliert und nicht weitergeworfen;
 *     der Versandstand steht in email_messages.
 */
final class SendFinalizationMails
{
    public function __construct(
        private readonly MailDispatcher $mailer,
        private readonly SignedDownloadLink $links,
    ) {}

    /**
     * Bewusst NICHT handle() und nicht __invoke(): Laravel 12 erkennt
     * Listener in app/Listeners selbsttaetig anhand dieser beiden
     * Methodennamen. Mit einem eigenen Namen bleibt die Registrierung
     * ausschliesslich die ausdrueckliche in ApplicationBindingsProvider. Die
     * Nachricht kann so nicht doppelt versendet werden.
     */
    public function versendeBestaetigungen(BillingRunFinalized $event): void
    {
        $billingRun = $event->billingRun;
        $empfaenger = $this->empfaenger($billingRun, $event->payment);

        if (! $empfaenger instanceof User) {
            Log::warning('Zu einem finalisierten Abrechnungslauf ist kein Empfaenger hinterlegt.', [
                'billing_run_id' => (string) $billingRun->getKey(),
            ]);

            return;
        }

        $adresse = $empfaenger->getAttribute('email');

        if (! is_string($adresse) || trim($adresse) === '') {
            return;
        }

        $rechnungsdokument = $event->invoice instanceof Invoice
            ? $this->rechnungsdokument($event->invoice)
            : null;

        $this->zahlungsbestaetigung($event, $empfaenger, $adresse, $rechnungsdokument);
        $this->finalabrechnungen($event, $empfaenger, $adresse);

        if ($event->invoice instanceof Invoice) {
            $this->rechnungsmail($event, $empfaenger, $adresse, $event->invoice, $rechnungsdokument);
        }

        if ($event->invoiceBlocked) {
            Log::info('Die Rechnung ist blockiert. Die Zahlungsbestaetigung ging ohne Rechnungsanhang.', [
                'billing_run_id' => (string) $billingRun->getKey(),
            ]);
        }
    }

    private function zahlungsbestaetigung(
        BillingRunFinalized $event,
        User $empfaenger,
        string $adresse,
        ?GeneratedDocument $rechnung,
    ): void {
        $billingRun = $event->billingRun;
        $zahlung = $event->payment;
        $bezahltAm = $zahlung->getAttribute('paid_at');

        $mail = new ZahlungBestaetigtMail(
            anrede: $this->anrede($empfaenger),
            objekt: $this->objekt($billingRun),
            jahr: (int) $billingRun->getAttribute('billing_year'),
            abrechnungen: max(0, (int) $zahlung->getAttribute('statement_count')),
            betragCent: (int) $zahlung->getAttribute('amount_cent'),
            bezahltAm: $bezahltAm instanceof Carbon ? $bezahltAm : Carbon::now(),
            portalUrl: $this->portalUrl($billingRun),
            rechnung: $event->invoiceBlocked ? null : $rechnung,
        );

        $this->versende($mail->template(), fn (): mixed => $this->mailer->send(
            mail: $mail,
            empfaenger: $adresse,
            nutzer: $empfaenger,
            organizationId: $this->organisation($billingRun),
            lauf: $billingRun,
        ));
    }

    private function finalabrechnungen(BillingRunFinalized $event, User $empfaenger, string $adresse): void
    {
        $billingRun = $event->billingRun;
        $dokument = $this->downloaddokument($event);

        if (! $dokument instanceof GeneratedDocument) {
            return;
        }

        $mail = new FinalabrechnungenVerfuegbarMail(
            anrede: $this->anrede($empfaenger),
            objekt: $this->objekt($billingRun),
            jahr: (int) $billingRun->getAttribute('billing_year'),
            abrechnungen: max(0, (int) $event->payment->getAttribute('statement_count')),
            downloadUrl: $this->signierterLink($dokument),
            gueltigkeitMinuten: $this->links->gueltigkeitMinuten(),
            portalUrl: $this->portalUrl($billingRun),
            downloadDokumentId: (string) $dokument->getKey(),
        );

        $this->versende($mail->template(), fn (): mixed => $this->mailer->send(
            mail: $mail,
            empfaenger: $adresse,
            nutzer: $empfaenger,
            organizationId: $this->organisation($billingRun),
            lauf: $billingRun,
        ));
    }

    private function rechnungsmail(
        BillingRunFinalized $event,
        User $empfaenger,
        string $adresse,
        Invoice $rechnung,
        ?GeneratedDocument $dokument,
    ): void {
        $ausgestellt = $rechnung->getAttribute('issued_on');

        $mail = new HvmRechnungVerfuegbarMail(
            anrede: $this->anrede($empfaenger),
            rechnungsnummer: (string) $rechnung->getAttribute('number'),
            bruttoCent: (int) $rechnung->getAttribute('gross_cent'),
            ausgestelltAm: $ausgestellt instanceof Carbon
                ? $ausgestellt->format('d.m.Y')
                : Carbon::now()->format('d.m.Y'),
            portalUrl: $this->portalUrl($event->billingRun),
            rechnung: $dokument,
        );

        $this->versende($mail->template(), fn (): mixed => $this->mailer->send(
            mail: $mail,
            empfaenger: $adresse,
            nutzer: $empfaenger,
            organizationId: $this->organisation($event->billingRun),
            lauf: $event->billingRun,
        ));
    }

    /**
     * Ein Versandfehler bleibt beim Versand. Die Finalisierung ist zu diesem
     * Zeitpunkt abgeschlossen und wird nicht zurueckgedreht.
     *
     * @param  callable(): mixed  $versand
     */
    private function versende(string $template, callable $versand): void
    {
        try {
            $versand();
        } catch (Throwable $fehler) {
            Log::error('Eine Transaktionsmail zur Finalisierung konnte nicht versendet werden.', [
                'template' => $template,
                'fehler' => $fehler->getMessage(),
            ]);
        }
    }

    /**
     * Zeitlich begrenzter, signierter Link auf die kontogebundene Route.
     *
     * Bewusst nicht ueber SignedDownloadLink::fuer(): die Bestaetigungsmail
     * verweist nach Abschnitt 16 auf portal.downloads.signed. Die
     * Gueltigkeitsdauer kommt weiterhin aus derselben Quelle.
     */
    private function signierterLink(GeneratedDocument $dokument): string
    {
        return URL::temporarySignedRoute(
            'portal.downloads.signed',
            Carbon::now()->addMinutes($this->links->gueltigkeitMinuten()),
            ['generatedDocument' => $dokument->getKey()],
        );
    }

    /**
     * Verlinkt wird das ZIP-Paket, sonst die erste finale Mieterabrechnung.
     * Angehaengt wird nie eine Abrechnung.
     */
    private function downloaddokument(BillingRunFinalized $event): ?GeneratedDocument
    {
        if (is_string($event->packageDocumentId) && $event->packageDocumentId !== '') {
            $paket = GeneratedDocument::query()->find($event->packageDocumentId);

            if ($paket instanceof GeneratedDocument) {
                return $paket;
            }
        }

        $dokument = GeneratedDocument::query()
            ->whereIn('id', $event->generatedDocumentIds)
            ->where('kind', GeneratedDocumentKind::MIETERABRECHNUNG->value)
            ->orderBy('created_at')
            ->first();

        return $dokument instanceof GeneratedDocument ? $dokument : null;
    }

    private function rechnungsdokument(Invoice $rechnung): ?GeneratedDocument
    {
        $dokument = GeneratedDocument::query()
            ->where('invoice_id', $rechnung->getKey())
            ->where('kind', GeneratedDocumentKind::HVM_RECHNUNG->value)
            ->where('variant', GeneratedDocumentVariant::FINAL->value)
            ->where('status', GeneratedDocumentStatus::AKTIV->value)
            ->orderByDesc('created_at')
            ->first();

        return $dokument instanceof GeneratedDocument ? $dokument : null;
    }

    private function empfaenger(BillingRun $billingRun, Payment $zahlung): ?User
    {
        foreach ([$zahlung->getAttribute('user_id'), $billingRun->getAttribute('created_by_user_id')] as $id) {
            $nutzer = is_string($id) && $id !== '' ? User::query()->find($id) : null;

            if ($nutzer instanceof User) {
                return $nutzer;
            }
        }

        return null;
    }

    private function objekt(BillingRun $billingRun): string
    {
        $billingRun->loadMissing('property');
        $objekt = $billingRun->getRelationValue('property');
        $label = $objekt instanceof Property ? $objekt->getAttribute('label') : null;

        return is_string($label) && trim($label) !== '' ? trim($label) : 'Ihr Objekt';
    }

    private function portalUrl(BillingRun $billingRun): string
    {
        return route('portal.abschluss.show', ['billingRun' => $billingRun->getKey()]);
    }

    private function organisation(BillingRun $billingRun): ?string
    {
        $id = $billingRun->getAttribute('organization_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function anrede(User $nutzer): string
    {
        $name = $nutzer->getAttribute('name');

        return is_string($name) && trim($name) !== ''
            ? 'Guten Tag '.trim($name).','
            : 'Guten Tag,';
    }
}
