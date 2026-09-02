<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Dto\CleanupReport;
use App\Application\Documents\Dto\DeletionReason;
use App\Enums\DocumentProcessingStatus;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Queue\DatabaseJobQueue;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Use Case: unabhaengiger TTL-Cleanup (Abschnitt 6.3 Schritt 17,
 * Abschnitt 19).
 *
 * VERBINDLICHE EIGENSCHAFTEN:
 *
 * 1. UNABHAENGIG. Der Lauf haengt nicht an der Verarbeitung, nicht am
 *    KI-Provider und nicht an einem laufenden Worker. Er loescht auch dann,
 *    wenn ein Job haengen geblieben ist, ein Browserfenster geschlossen wurde
 *    oder ein Providerabruf nie zurueckkam.
 * 2. IDEMPOTENT. Ein zweiter Lauf findet nichts mehr und aendert nichts. Die
 *    Auswahl greift nur auf Datensaetze ohne Tombstone; nach erfolgreicher
 *    Loeschung sind sie Tombstone und damit unsichtbar fuer den naechsten Lauf.
 * 3. STAPELWEISE. Ein Lauf ist auf eine Hoechstzahl begrenzt, damit er in
 *    einen Cron-Lauf mit begrenzter Laufzeit passt. Der Rest folgt im
 *    naechsten Intervall.
 * 4. VERWAISTE RESTE. Zusaetzlich werden Verzeichnisse im Kurzzeitbereich
 *    entfernt, zu denen kein offener Datensatz mehr existiert und deren
 *    juengste Datei aelter als die Hoechst-TTL ist. Solche Reste entstehen,
 *    wenn ein Prozess zwischen Dateischreiben und Datensatz abbricht. Ihre
 *    Chiffrate sind ohne Datensatz ohnehin nicht mehr entschluesselbar.
 *
 * Die TTL beginnt mit dem Eingang des ersten Abschnitts und ist in der
 * Konfiguration hart auf 120 Minuten begrenzt.
 */
final class CleanupExpiredUploads
{
    public const DEFAULT_BATCH_SIZE = 100;

    /**
     * Verwaiste Verzeichnisse werden erst nach der Hoechst-TTL entfernt, damit
     * ein gerade laufender Schreibvorgang ohne Datensatz nicht getroffen wird.
     */
    public const ORPHAN_MAX_AGE_MINUTES = 120;

    public function __construct(
        private readonly DeleteOriginalSources $deleteSources,
        private readonly DatabaseJobQueue $queue,
        private readonly TemporaryUploadStorage $storage,
    ) {}

    public function __invoke(int $batchSize = self::DEFAULT_BATCH_SIZE): CleanupReport
    {
        $expired = TemporaryUpload::query()
            ->expired()
            ->orderBy('expires_at')
            ->limit(max(1, $batchSize))
            ->get();

        $inspected = 0;
        $deleted = 0;
        $failed = 0;
        $alreadyDeleted = 0;
        $cancelledJobs = 0;

        foreach ($expired as $upload) {
            $inspected++;

            $document = Document::query()->whereKey($upload->getAttribute('document_id'))->first();

            if (! $document instanceof Document) {
                // Das Dokument wurde bereits entfernt. Die Quelldatei darf
                // trotzdem nicht liegen bleiben.
                $upload->forceFill(['is_tombstone' => true, 'storage_key' => null, 'encryption_key_wrapped' => null])->save();
                $alreadyDeleted++;

                continue;
            }

            $cancelledJobs += $this->queue->cancelForDocument((string) $document->getKey());

            $this->markAborted($document);

            $outcome = ($this->deleteSources)($document, DeletionReason::TTL_ABGELAUFEN);

            if ($outcome->alreadyDeleted) {
                $alreadyDeleted++;
            } elseif ($outcome->isSuccessful()) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        $orphaned = $this->removeOrphanedPrefixes();

        return new CleanupReport($inspected, $deleted, $failed, $alreadyDeleted, $cancelledJobs, $orphaned);
    }

    /**
     * Ein noch laufendes Dokument gilt als abgebrochen. Ein bereits
     * abgeschlossenes Dokument behaelt seinen Status: dort ist die Loeschung
     * nur nachgelaufen, die Auswertung war erfolgreich.
     */
    private function markAborted(Document $document): void
    {
        $status = $document->getAttribute('processing_status');

        if ($status instanceof DocumentProcessingStatus && $status->requiresSourceDeletion()) {
            return;
        }

        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::ABGEBROCHEN,
            'failure_code' => UploadErrorCode::TTL_ABGELAUFEN->value,
            'failure_message' => mb_substr(UploadErrorCode::TTL_ABGELAUFEN->message(), 0, 500),
        ])->save();
    }

    /**
     * Entfernt Verzeichnisse ohne offenen Datensatz, sobald sie aelter als die
     * Hoechst-TTL sind. Leere Verzeichnisse werden sofort entfernt.
     *
     * @return int Anzahl entfernter Praefixe
     */
    private function removeOrphanedPrefixes(): int
    {
        $cutoff = Carbon::now()->subMinutes(self::ORPHAN_MAX_AGE_MINUTES)->getTimestamp();
        $removed = 0;

        foreach ($this->storage->allPrefixes() as $prefix) {
            $hasRecord = TemporaryUpload::query()
                ->where('storage_key', $prefix)
                ->where('is_tombstone', false)
                ->exists();

            if ($hasRecord) {
                continue;
            }

            $lastModified = $this->storage->lastModifiedAt($prefix);

            if ($lastModified !== null && $lastModified > $cutoff) {
                continue;
            }

            try {
                if ($this->storage->deletePrefix($prefix)) {
                    $removed++;
                }
            } catch (Throwable) {
                // Ein einzelner Fehlschlag haelt den Lauf nicht auf. Der Rest
                // wird im naechsten Intervall erneut versucht.
            }
        }

        return $removed;
    }
}
