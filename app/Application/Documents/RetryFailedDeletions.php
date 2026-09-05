<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Dto\CleanupReport;
use App\Application\Documents\Dto\DeletionReason;
use App\Enums\DeletionStatus;
use App\Models\Document;
use App\Models\TemporaryUpload;

/**
 * Use Case: fehlgeschlagene Loeschungen wiederholen.
 *
 * Eine fehlgeschlagene Loeschung ist ein kritischer Datenschutzvorfall in
 * Bearbeitung, kein normaler Fehler: Es liegt weiterhin eine Originaldatei im
 * Kurzzeitbereich, obwohl sie geloescht sein muesste. Der Vorgang wird deshalb
 * regelmaessig wiederholt und im Adminbereich als Alarm gefuehrt
 * (Abschnitt 19).
 *
 * Auswahl:
 * - Kurzzeitdatensaetze ohne Tombstone, deren Dokument den Loeschstatus
 *   FEHLGESCHLAGEN oder UEBERFAELLIG traegt
 * - Kurzzeitdatensaetze, deren Providerloeschung fehlgeschlagen ist
 *
 * Der Lauf ist idempotent: Ein bereits erledigter Fall faellt aus der Auswahl.
 */
final class RetryFailedDeletions
{
    public const DEFAULT_BATCH_SIZE = 50;

    public function __construct(private readonly DeleteOriginalSources $deleteSources) {}

    public function __invoke(int $batchSize = self::DEFAULT_BATCH_SIZE): CleanupReport
    {
        $documentIds = Document::query()
            ->whereIn('deletion_status', [
                DeletionStatus::FEHLGESCHLAGEN->value,
                DeletionStatus::UEBERFAELLIG->value,
            ])
            ->pluck('id')
            ->all();

        $uploads = TemporaryUpload::query()
            ->where('is_tombstone', false)
            ->where(function ($query) use ($documentIds): void {
                $query->whereIn('document_id', $documentIds)
                    ->orWhere('provider_deletion_status', DeletionStatus::FEHLGESCHLAGEN->value);
            })
            ->orderBy('updated_at')
            ->limit(max(1, $batchSize))
            ->get();

        $inspected = 0;
        $deleted = 0;
        $failed = 0;
        $alreadyDeleted = 0;

        foreach ($uploads as $upload) {
            $document = Document::query()->whereKey($upload->getAttribute('document_id'))->first();

            if (! $document instanceof Document) {
                $upload->forceFill(['is_tombstone' => true, 'storage_key' => null])->save();
                $alreadyDeleted++;

                continue;
            }

            $inspected++;

            $outcome = ($this->deleteSources)($document, DeletionReason::WIEDERHOLUNG);

            if ($outcome->alreadyDeleted) {
                $alreadyDeleted++;
            } elseif ($outcome->isSuccessful()) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        return new CleanupReport($inspected, $deleted, $failed, $alreadyDeleted);
    }

    /**
     * Anzahl der offenen Datenschutzalarme. Grundlage der Anzeige im
     * Adminbereich und des Livegang-Blockers.
     */
    public function openAlertCount(): int
    {
        return Document::query()
            ->whereIn('deletion_status', [
                DeletionStatus::FEHLGESCHLAGEN->value,
                DeletionStatus::UEBERFAELLIG->value,
            ])
            ->count();
    }
}
