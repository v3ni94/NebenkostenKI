<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Application\Reconciliation\Dto\ReconciliationOutcome;
use App\Enums\DocumentProcessingStatus;
use App\Mail\DokumentverarbeitungAbgeschlossenMail;
use App\Mail\MailDispatcher;
use App\Mail\PruefaufgabenOffenMail;
use App\Mail\TransactionalMail;
use App\Mail\VerarbeitungsfehlerMail;
use App\Mail\VorschauBereitMail;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verdrahtung der Statusmails zur Verarbeitung (Masterprompt 16).
 *
 * Die vier Nachrichten existieren als Vorlagen, dieser Listener bindet sie an
 * die vorhandenen Ereignisse:
 *
 *  - Dokumentverarbeitung abgeschlossen und Verarbeitungsfehler haengen am
 *    Eloquent-Ereignis "updated" des Dokuments. Sobald das letzte Dokument
 *    eines Laufs einen Endzustand erreicht, geht genau eine Nachricht: bei
 *    nicht auswertbaren Unterlagen die kritische Fehlermeldung, sonst die
 *    Abschlussmeldung. Der Nutzer erfaehrt so auch nach dem Schliessen des
 *    Browsers, dass die Auswertung beendet ist.
 *  - Pruefaufgaben offen wird nach der Zuordnung (Schritt 3) versendet, wenn
 *    Vorschlaege oder Pruefaufgaben zu entscheiden sind.
 *  - Vorschau bereit wird nach der Erzeugung der Vorschau (Schritt 10)
 *    versendet.
 *
 * Der Versand laeuft ausschliesslich ueber den MailDispatcher. Damit gelten
 * Protokoll und Sperrliste: eine abgemeldete oder gesperrte Adresse erhaelt
 * die gewoehnlichen Statusmails nicht mehr, die kritische Fehlermeldung
 * dagegen weiterhin (Masterprompt 17.2). Ein Versandfehler bleibt beim
 * Versand und unterbricht weder Pipeline noch Ablauf.
 */
final class SendProcessingStatusMails
{
    public function __construct(private readonly MailDispatcher $mailer) {}

    /**
     * Eloquent-Ereignis "updated" eines Dokuments. Bewusst kein handle() und
     * kein __invoke(), damit Laravel den Listener nicht zusaetzlich
     * automatisch registriert.
     */
    public function dokumentAktualisiert(Document $document): void
    {
        if (! $document->isDirty('processing_status') && ! $document->wasChanged('processing_status')) {
            return;
        }

        $status = $document->getAttribute('processing_status');

        if (! $status instanceof DocumentProcessingStatus || ! $status->requiresSourceDeletion()) {
            return;
        }

        try {
            $this->verarbeitungBeendet($document);
        } catch (Throwable $fehler) {
            Log::error('Die Statusmail zur Dokumentverarbeitung konnte nicht versendet werden.', [
                'document_id' => (string) $document->getKey(),
                'fehler' => $fehler->getMessage(),
            ]);
        }
    }

    private function verarbeitungBeendet(Document $document): void
    {
        $billingRun = $document->getRelationValue('billingRun');

        if (! $billingRun instanceof BillingRun) {
            $billingRun = BillingRun::query()->find($document->getAttribute('billing_run_id'));
        }

        if (! $billingRun instanceof BillingRun) {
            return;
        }

        $dokumente = Document::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->get(['id', 'processing_status', 'duplicate_of_document_id']);

        $gesamt = 0;
        $ausgewertet = 0;
        $nichtAuswertbar = 0;

        foreach ($dokumente as $eintrag) {
            $gesamt++;
            $zustand = $eintrag->getAttribute('processing_status');

            if (! $zustand instanceof DocumentProcessingStatus || ! $zustand->requiresSourceDeletion()) {
                // Mindestens ein Dokument ist noch in Arbeit.
                return;
            }

            if ($zustand === DocumentProcessingStatus::ABGESCHLOSSEN) {
                $ausgewertet++;

                continue;
            }

            // Eine Dublette ist kein Fehler, sie ist bewusst nicht ausgewertet.
            if ($zustand === DocumentProcessingStatus::ABGELEHNT
                && $eintrag->getAttribute('duplicate_of_document_id') !== null) {
                continue;
            }

            $nichtAuswertbar++;
        }

        if ($gesamt === 0) {
            return;
        }

        $empfaenger = $this->empfaenger($billingRun);

        if (! $empfaenger instanceof User) {
            return;
        }

        $portalUrl = route('portal.pruefung.analyse', ['billingRun' => $billingRun->getKey()]);

        if ($nichtAuswertbar > 0) {
            $mail = new VerarbeitungsfehlerMail(
                anrede: $this->anrede($empfaenger),
                objekt: $this->objekt($billingRun),
                jahr: (int) $billingRun->getAttribute('billing_year'),
                sachverhalt: sprintf(
                    '%d von %d Unterlagen konnten nicht ausgewertet werden. Die Originaldateien wurden wie '
                    .'vorgesehen gelöscht.',
                    $nichtAuswertbar,
                    $gesamt
                ),
                empfehlung: 'Bitte laden Sie die betroffenen Unterlagen erneut hoch oder erfassen Sie die Werte '
                    .'manuell in der Kostenprüfung. Ihre bereits ausgewerteten Unterlagen bleiben erhalten.',
                portalUrl: $portalUrl,
            );
        } else {
            $mail = new DokumentverarbeitungAbgeschlossenMail(
                anrede: $this->anrede($empfaenger),
                objekt: $this->objekt($billingRun),
                jahr: (int) $billingRun->getAttribute('billing_year'),
                dokumente: $ausgewertet,
                portalUrl: $portalUrl,
            );
        }

        $this->versende($mail, $billingRun, $empfaenger);
    }

    /**
     * Nach der Zuordnung (Schritt 3): es sind Vorschlaege oder Pruefaufgaben
     * zu entscheiden.
     */
    public function pruefaufgabenOffen(BillingRun $billingRun, User $empfaenger, ReconciliationOutcome $outcome): void
    {
        $offen = $outcome->proposalsCreated + $outcome->openIssueCount;

        if ($offen <= 0) {
            return;
        }

        $themen = [];

        if ($outcome->proposalsCreated > 0) {
            $themen[] = sprintf('%d vorgeschlagene Kostenpositionen bestätigen oder verwerfen', $outcome->proposalsCreated);
        }

        if ($outcome->openIssueCount > 0) {
            $themen[] = sprintf('%d Prüfaufgaben klären', $outcome->openIssueCount);
        }

        $mail = new PruefaufgabenOffenMail(
            anrede: $this->anrede($empfaenger),
            objekt: $this->objekt($billingRun),
            jahr: (int) $billingRun->getAttribute('billing_year'),
            offen: $offen,
            themen: $themen,
            portalUrl: route('portal.pruefung.kosten', ['billingRun' => $billingRun->getKey()]),
        );

        $this->versende($mail, $billingRun, $empfaenger);
    }

    /**
     * Nach der Erzeugung der Vorschau (Schritt 10).
     */
    public function vorschauBereit(BillingRun $billingRun, User $empfaenger, int $abrechnungen, int $preisGesamtCent): void
    {
        $mail = new VorschauBereitMail(
            anrede: $this->anrede($empfaenger),
            objekt: $this->objekt($billingRun),
            jahr: (int) $billingRun->getAttribute('billing_year'),
            abrechnungen: max(0, $abrechnungen),
            preisGesamtCent: max(0, $preisGesamtCent),
            portalUrl: route('portal.wizard.vorschau', ['billingRun' => $billingRun->getKey()]),
        );

        $this->versende($mail, $billingRun, $empfaenger);
    }

    /**
     * Ein Versandfehler bleibt beim Versand; der fachliche Ablauf ist zu
     * diesem Zeitpunkt abgeschlossen und wird nicht zurueckgedreht.
     */
    private function versende(TransactionalMail $mail, BillingRun $billingRun, User $empfaenger): void
    {
        $adresse = $empfaenger->getAttribute('email');

        if (! is_string($adresse) || trim($adresse) === '') {
            return;
        }

        $organizationId = $billingRun->getAttribute('organization_id');

        try {
            $this->mailer->send(
                mail: $mail,
                empfaenger: $adresse,
                nutzer: $empfaenger,
                organizationId: is_string($organizationId) && $organizationId !== '' ? $organizationId : null,
                lauf: $billingRun,
            );
        } catch (Throwable $fehler) {
            Log::error('Eine Statusmail konnte nicht versendet werden.', [
                'template' => $mail->template(),
                'billing_run_id' => (string) $billingRun->getKey(),
                'fehler' => $fehler->getMessage(),
            ]);
        }
    }

    private function empfaenger(BillingRun $billingRun): ?User
    {
        $id = $billingRun->getAttribute('created_by_user_id');
        $nutzer = is_string($id) && $id !== '' ? User::query()->find($id) : null;

        return $nutzer instanceof User ? $nutzer : null;
    }

    private function objekt(BillingRun $billingRun): string
    {
        $billingRun->loadMissing('property');
        $objekt = $billingRun->getRelationValue('property');
        $label = $objekt instanceof Property ? $objekt->getAttribute('label') : null;

        return is_string($label) && trim($label) !== '' ? trim($label) : 'Ihr Objekt';
    }

    private function anrede(User $nutzer): string
    {
        $name = $nutzer->getAttribute('name');

        return is_string($name) && trim($name) !== ''
            ? 'Guten Tag '.trim($name).','
            : 'Guten Tag,';
    }
}
