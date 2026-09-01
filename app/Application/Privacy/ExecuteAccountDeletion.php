<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Application\Account\AuditRecorder;
use App\Application\Privacy\Dto\AccountDeletionReport;
use App\Enums\GeneratedDocumentKind;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ReminderEvent;
use App\Models\User;
use App\Services\Storage\ArtifactStorage;
use Illuminate\Support\Facades\DB;

/**
 * Endgültige Löschung eines Kontos nach Ablauf der Frist (Masterprompt 19).
 *
 * REIHENFOLGE, verbindlich:
 *
 *  1. Rechnungen der Hausverwaltung Müller GmbH ENTKOPPELN, nicht löschen.
 *     Sie sind handels- und steuerrechtlich aufzubewahren. Entkoppelt heißt:
 *     organization_id, user_id, billing_run_id und payment_id werden auf null
 *     gesetzt. Erhalten bleiben ausschließlich die für die Aufbewahrung
 *     erforderlichen Rechnungsangaben.
 *  2. Die zugehörigen Rechnungs-PDFs ebenfalls entkoppeln, damit sie beim
 *     Löschen des Abrechnungslaufs nicht mitgelöscht werden.
 *  3. Alle übrigen erzeugten Artefakte, insbesondere Abrechnungs-PDFs,
 *     Vorschauen, ZIP-Pakete und frühere Datenexporte, samt Datei löschen.
 *  4. Nachrichten und Erinnerungsereignisse mit Bezug auf den Nutzer löschen,
 *     auch wenn ihr Mandantenbezug schon fehlt, weil sie die E-Mail-Adresse
 *     enthalten.
 *  5. Den Mandanten endgültig löschen. Die Fremdschlüssel des Datenmodells
 *     räumen die Fachdaten kaskadierend ab.
 *  6. Den Nutzer endgültig löschen. Die Verweise im Revisionsprotokoll werden
 *     dadurch auf null gesetzt; der Nachweis bleibt inhaltlich erhalten.
 *  7. Einen Eintrag über die Ausführung schreiben, ohne Kontaktdaten.
 *
 * IDEMPOTENZ UND WIEDERAUFNAHME: Jeder Schritt prüft seinen Zustand, ein
 * zweiter Aufruf ist folgenlos. Bricht der Lauf ab, bleibt der Antrag im
 * Revisionsprotokoll offen und wird im nächsten Lauf wieder aufgenommen. Ein
 * Mandant mit weiteren Mitgliedern wird nicht gelöscht, sondern der Nutzer nur
 * aus ihm entfernt.
 *
 * GRENZEN, die nicht behauptet werden: Es wird nicht behauptet, eine Datei
 * ließe sich auf gemeinsam genutztem oder SSD-basiertem Storage forensisch
 * überschreiben. Verbindlich sind logische Löschung, Ausschluss aus Backups,
 * kurze Aufbewahrung im Kurzzeitbereich und ein dokumentierter Löschstatus.
 */
final class ExecuteAccountDeletion
{
    public function __construct(
        private readonly AccountDeletionWorkflow $workflow,
        private readonly ArtifactStorage $artifacts,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(User $user, bool $force = false): AccountDeletionReport
    {
        if (! $force && ! $this->workflow->state($user)->isDue()) {
            return AccountDeletionReport::skipped();
        }

        $userId = (string) $user->getKey();
        $eigene = $this->soleOwnedOrganizations($user);

        $entkoppelteRechnungen = 0;
        $erhalteneBelege = 0;
        $geloeschteDokumente = 0;
        $geloeschteDateien = 0;
        $fehler = 0;
        $geloeschteMandanten = 0;

        foreach ($eigene as $organisation) {
            $organisationId = (string) $organisation->getKey();

            $entkoppelteRechnungen += $this->decoupleInvoices($organisationId, $userId);
            $erhalteneBelege += $this->decoupleInvoiceDocuments($organisationId);

            $bericht = $this->deleteArtifacts($organisationId);
            $geloeschteDokumente += $bericht['documents'];
            $geloeschteDateien += $bericht['files'];
            $fehler += $bericht['failed'];
        }

        // Nachrichten und Erinnerungsereignisse enthalten die E-Mail-Adresse.
        // Sie werden auch dann entfernt, wenn der Mandantenbezug bereits fehlt.
        EmailMessage::query()->where('user_id', $userId)->delete();
        ReminderEvent::query()->where('user_id', $userId)->delete();

        $email = $user->getAttribute('email');

        if (is_string($email) && $email !== '') {
            EmailSuppression::query()->where('email', $email)->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        }

        DB::table('sessions')->where('user_id', $userId)->delete();

        // Mitgliedschaften in fremden Mandanten enden, der fremde Mandant
        // bleibt unberührt.
        OrganizationUser::query()->where('user_id', $userId)->delete();

        foreach ($eigene as $organisation) {
            $organisation->forceDelete();
            $geloeschteMandanten++;
        }

        $user->forceDelete();

        $this->audit->record(
            action: AccountDeletionWorkflow::ACTION_EXECUTED,
            metadata: [
                'user_reference' => $userId,
                'deleted_organizations' => $geloeschteMandanten,
                'decoupled_invoices' => $entkoppelteRechnungen,
                'retained_invoice_documents' => $erhalteneBelege,
                'deleted_generated_documents' => $geloeschteDokumente,
                'deleted_artifact_files' => $geloeschteDateien,
                'failed_artifact_files' => $fehler,
            ],
            reason: 'Endgültige Kontolöschung nach Ablauf der Frist',
        );

        return new AccountDeletionReport(
            executed: true,
            deletedOrganizations: $geloeschteMandanten,
            decoupledInvoices: $entkoppelteRechnungen,
            retainedInvoiceDocuments: $erhalteneBelege,
            deletedGeneratedDocuments: $geloeschteDokumente,
            deletedArtifacts: $geloeschteDateien,
            failedArtifacts: $fehler,
        );
    }

    /**
     * Mandanten, in denen der Nutzer das einzige Mitglied ist. Nur diese werden
     * gelöscht, damit kein fremder Datenbestand mit entfernt wird.
     *
     * @return list<Organization>
     */
    private function soleOwnedOrganizations(User $user): array
    {
        $ids = $user->organizationIds();

        if ($ids === []) {
            return [];
        }

        $ergebnis = [];

        foreach ($ids as $id) {
            $weitere = OrganizationUser::query()
                ->where('organization_id', $id)
                ->where('user_id', '!=', $user->getKey())
                ->exists();

            if ($weitere) {
                continue;
            }

            /** @var Organization|null $organisation */
            $organisation = Organization::query()->withTrashed()->whereKey($id)->first();

            if ($organisation instanceof Organization) {
                $ergebnis[] = $organisation;
            }
        }

        return $ergebnis;
    }

    /**
     * Rechnungen entkoppeln. Die aufbewahrungspflichtigen Angaben bleiben
     * unverändert, jeder Bezug zum gelöschten Konto entfällt.
     */
    private function decoupleInvoices(string $organizationId, string $userId): int
    {
        $entkoppelt = [
            'organization_id' => null,
            'user_id' => null,
            'billing_run_id' => null,
            'payment_id' => null,
        ];

        $anzahl = Invoice::query()
            ->where('organization_id', $organizationId)
            ->update($entkoppelt);

        $anzahl += Invoice::query()
            ->where('user_id', $userId)
            ->update($entkoppelt);

        return $anzahl;
    }

    /**
     * Rechnungs-PDFs entkoppeln, damit sie das Löschen des Mandanten und des
     * Abrechnungslaufs überdauern.
     */
    private function decoupleInvoiceDocuments(string $organizationId): int
    {
        return GeneratedDocument::query()
            ->where('organization_id', $organizationId)
            ->where('kind', GeneratedDocumentKind::HVM_RECHNUNG->value)
            ->update([
                'organization_id' => null,
                'billing_run_id' => null,
                'unit_statement_id' => null,
                'calculation_snapshot_id' => null,
            ]);
    }

    /**
     * Alle übrigen erzeugten Artefakte samt Datei entfernen.
     *
     * @return array{documents: int, files: int, failed: int}
     */
    private function deleteArtifacts(string $organizationId): array
    {
        /** @var list<GeneratedDocument> $dokumente */
        $dokumente = GeneratedDocument::query()
            ->where('organization_id', $organizationId)
            ->get()
            ->all();

        $dateien = 0;
        $fehler = 0;
        $anzahl = 0;

        foreach ($dokumente as $dokument) {
            $pfad = $dokument->getAttribute('storage_path');

            if (is_string($pfad) && $pfad !== '') {
                if ($this->artifacts->exists($pfad)) {
                    if ($this->artifacts->delete($pfad)) {
                        $dateien++;
                    } else {
                        $fehler++;
                    }
                }
            }

            $dokument->delete();
            $anzahl++;
        }

        return ['documents' => $anzahl, 'files' => $dateien, 'failed' => $fehler];
    }
}
