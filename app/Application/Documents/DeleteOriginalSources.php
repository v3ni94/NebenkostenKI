<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Dto\DeletionOutcome;
use App\Application\Documents\Dto\DeletionReason;
use App\Application\Documents\Support\ProviderFileDeleterResolver;
use App\Enums\AiProvider;
use App\Enums\DeletionStatus;
use App\Models\Document;
use App\Models\SourceDeletionEvent;
use App\Models\TemporaryUpload;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Loescht alle Quelldaten eines Dokuments und weist die Loeschung nach.
 *
 * DAS IST DAS KERNSTUECK DES DATENSCHUTZKONZEPTS (ADR-007, Abschnitt 6.3
 * Schritte 14 bis 18, Abschnitt 19).
 *
 * REIHENFOLGE, verbindlich:
 *   1. Providerdatei ueber die Loeschschnittstelle entfernen. Zuerst, weil sie
 *      ausserhalb der eigenen Kontrolle liegt und ein Fehlschlag hier den
 *      lokalen Loeschpfad NICHT aufhalten darf.
 *   2. Lokale Originaldatei, Seitenbilder, Konvertierungen, entpackte
 *      Archiveintraege und den vollstaendigen OCR-Text entfernen. Alles liegt
 *      unter demselben zufaelligen Praefix und wird in einem Zug geloescht,
 *      damit keine Ableitung uebersehen wird.
 *   3. temporary_uploads auf einen inhaltslosen Tombstone reduzieren:
 *      storage_key null, provider_file_id null, is_tombstone true.
 *   4. documents.original_deleted_at und deletion_status setzen.
 *   5. In jedem Fall einen Eintrag in source_deletion_events schreiben, mit
 *      Dokument-ID, Zeitpunkt, Status, Versuch und Fehlercode, ohne jeden
 *      Dateiinhalt und ohne Dateinamen.
 *
 * IDEMPOTENZ: Ein bereits erledigter Vorgang erzeugt keinen weiteren Eintrag
 * und aendert nichts. Ein zweiter Aufruf ist damit folgenlos, was der
 * TTL-Cleanup ausdruecklich benoetigt.
 *
 * FEHLER: Ein Fehlschlag setzt deletion_status auf FEHLGESCHLAGEN, erhoeht
 * deletion_attempts und schreibt den Fehlercode. Der Adminbereich zeigt das
 * als kritischen Datenschutzalarm; RetryFailedDeletionsCommand wiederholt.
 */
final class DeleteOriginalSources
{
    /**
     * Ab dieser Zahl erfolgloser Versuche gilt die Loeschung als ueberfaellig
     * und wird im Adminbereich als kritischer Alarm gefuehrt.
     */
    public const OVERDUE_AFTER_ATTEMPTS = 3;

    public function __construct(
        private readonly TemporaryUploadStorage $storage,
        private readonly ProviderFileDeleterResolver $providerDeleters,
    ) {}

    public function __invoke(Document $document, DeletionReason $reason): DeletionOutcome
    {
        $upload = TemporaryUpload::query()
            ->where('document_id', $document->getKey())
            ->where('is_tombstone', false)
            ->first();

        if (! $upload instanceof TemporaryUpload) {
            return $this->finishWithoutUpload($document);
        }

        $attempt = (int) $upload->getAttribute('deletion_attempts') + 1;

        $document->forceFill(['deletion_status' => DeletionStatus::IN_ARBEIT])->save();

        $providerStatus = $this->deleteProviderFile($upload);
        $localResult = $this->deleteLocalSources($upload);

        $now = Carbon::now();

        if ($localResult['deleted'] && $providerStatus !== DeletionStatus::FEHLGESCHLAGEN) {
            $this->reduceToTombstone($upload, $providerStatus, $now);

            $document->forceFill([
                'original_deleted_at' => $now,
                'deletion_status' => DeletionStatus::ERFOLGREICH,
            ])->save();

            $outcome = new DeletionOutcome(DeletionStatus::ERFOLGREICH, $providerStatus, $attempt);

            $this->recordEvent($document, $upload, $outcome, $now, $reason);

            return $outcome;
        }

        $errorCode = $localResult['deleted']
            ? UploadErrorCode::PROVIDER_LOESCHUNG_FEHLGESCHLAGEN
            : UploadErrorCode::LOESCHUNG_FEHLGESCHLAGEN;

        $localStatus = $localResult['deleted'] ? DeletionStatus::ERFOLGREICH : DeletionStatus::FEHLGESCHLAGEN;

        $documentStatus = $attempt >= self::OVERDUE_AFTER_ATTEMPTS
            ? DeletionStatus::UEBERFAELLIG
            : DeletionStatus::FEHLGESCHLAGEN;

        $upload->forceFill([
            'deletion_attempts' => $attempt,
            'last_error' => $errorCode->message(),
            'provider_deletion_status' => $providerStatus,
            'storage_key' => $localResult['deleted'] ? null : $upload->getAttribute('storage_key'),
        ])->save();

        $document->forceFill(['deletion_status' => $documentStatus])->save();

        $outcome = new DeletionOutcome(
            $localStatus,
            $providerStatus,
            $attempt,
            false,
            $errorCode->value,
        );

        $this->recordEvent($document, $upload, $outcome, $now, $reason);

        return $outcome;
    }

    /**
     * Es gibt keinen offenen Kurzzeitdatensatz mehr. Der Zielzustand ist damit
     * erreicht. Es wird kein weiterer Nachweis geschrieben, damit ein zweiter
     * Lauf wirklich nichts aendert.
     */
    private function finishWithoutUpload(Document $document): DeletionOutcome
    {
        if ($document->getAttribute('original_deleted_at') === null) {
            $document->forceFill([
                'original_deleted_at' => Carbon::now(),
                'deletion_status' => DeletionStatus::ERFOLGREICH,
            ])->save();
        } elseif ($document->getAttribute('deletion_status') !== DeletionStatus::ERFOLGREICH) {
            $document->forceFill(['deletion_status' => DeletionStatus::ERFOLGREICH])->save();
        }

        return new DeletionOutcome(
            DeletionStatus::ERFOLGREICH,
            DeletionStatus::NICHT_ERFORDERLICH,
            1,
            true,
        );
    }

    /**
     * Ein Fehlschlag beim Provider haelt die lokale Loeschung nicht auf.
     */
    private function deleteProviderFile(TemporaryUpload $upload): DeletionStatus
    {
        $providerFileId = $upload->getAttribute('provider_file_id');
        $provider = $upload->getAttribute('provider');

        if (! is_string($providerFileId) || $providerFileId === '' || ! $provider instanceof AiProvider) {
            return DeletionStatus::NICHT_ERFORDERLICH;
        }

        try {
            $report = $this->providerDeleters->resolve()->deleteProviderFile($provider, $providerFileId);
        } catch (Throwable) {
            // Auch eine unerwartete Ausnahme der Provideranbindung darf den
            // lokalen Loeschpfad nicht verhindern. Die Meldung wird bewusst
            // nicht uebernommen, sie koennte Providerantworten enthalten.
            return DeletionStatus::FEHLGESCHLAGEN;
        }

        return $report->status;
    }

    /**
     * @return array{deleted: bool, files_left: int}
     */
    private function deleteLocalSources(TemporaryUpload $upload): array
    {
        $prefix = $upload->getAttribute('storage_key');

        if (! is_string($prefix) || $prefix === '') {
            // Kein Schluessel mehr vorhanden: bereits geloescht.
            return ['deleted' => true, 'files_left' => 0];
        }

        try {
            $deleted = $this->storage->deletePrefix($prefix);
            $filesLeft = $deleted ? 0 : $this->storage->countFiles($prefix);
        } catch (Throwable) {
            return ['deleted' => false, 'files_left' => -1];
        }

        return ['deleted' => $deleted, 'files_left' => $filesLeft];
    }

    /**
     * Inhaltsloser Tombstone. Der Datensatz bleibt als Nachweis bestehen, traegt
     * aber keinen Storage-Key und keine Provider-Datei-ID mehr
     * (Abschnitt 6.4: temporaere Provider-Datei-IDs werden nach Abschluss der
     * Verarbeitung nicht dauerhaft gespeichert).
     */
    private function reduceToTombstone(TemporaryUpload $upload, DeletionStatus $providerStatus, Carbon $now): void
    {
        $upload->forceFill([
            'storage_key' => null,
            'provider_file_id' => null,
            'provider_file_deleted_at' => $providerStatus === DeletionStatus::ERFOLGREICH ? $now : null,
            'provider_deletion_status' => $providerStatus,
            'deleted_at' => $now,
            'is_tombstone' => true,
            'last_error' => null,
        ])->save();
    }

    /**
     * Datensparsamer Loeschnachweis. Enthaelt ausdruecklich keinen
     * Storage-Key, keinen Dateinamen und keinen Inhalt.
     */
    private function recordEvent(
        Document $document,
        TemporaryUpload $upload,
        DeletionOutcome $outcome,
        Carbon $now,
        DeletionReason $reason,
    ): void {
        $event = new SourceDeletionEvent;

        $event->fill([
            'organization_id' => $document->getAttribute('organization_id'),
            'document_id' => $document->getKey(),
            'temporary_upload_id' => $upload->getKey(),
            'local_deletion_status' => $outcome->localStatus,
            'provider_deletion_status' => $outcome->providerStatus,
            'provider' => $upload->getAttribute('provider'),
            'occurred_at' => $now,
            'attempt' => $outcome->attempt,
            'error_code' => $outcome->errorCode,
            'error_message' => $outcome->errorCode === null
                ? null
                : sprintf('%s (%s)', $outcome->errorCode, $reason->label()),
        ]);

        $event->save();
    }
}
