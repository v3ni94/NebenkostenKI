<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Support\SourceLabelFactory;
use App\Enums\DeletionStatus;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentRelationType;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentRelation;
use App\Models\TemporaryUpload;
use App\Services\Storage\ArchiveEntryReport;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\FingerprintFactory;
use App\Services\Storage\MimeGuard;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use App\Services\Storage\UploadLimits;
use Illuminate\Support\Carbon;
use RuntimeException;
use ZipArchive;

/**
 * Use Case: ein geprueftes Archiv in einzelne Dokumente aufloesen.
 *
 * Aufgerufen erst NACHDEM ArchiveGuard jeden Eintrag einzeln geprueft hat.
 * Ein Archiv wird niemals blind entpackt.
 *
 * DATENSCHUTZ:
 * - Der Eintragsname des Archivs ist ein Originaldateiname. Er wird nur zur
 *   Pruefung gelesen und niemals gespeichert. Das entpackte Dokument erhaelt
 *   eine neue neutrale Quellenbezeichnung.
 * - Jeder Eintrag landet unter einem eigenen zufaelligen Praefix im
 *   Quarantaenebereich, mit eigener TTL und eigenem Loeschnachweis.
 * - Das Archiv selbst bleibt als Containerdokument bestehen, damit die
 *   Herkunft der Einzeldokumente nachvollziehbar ist. Seine Quelldatei wird
 *   nach der Aufloesung geloescht wie jede andere Quelldatei.
 */
final class ExpandArchive
{
    public function __construct(
        private readonly TemporaryUploadStorage $storage,
        private readonly MimeGuard $mimeGuard,
        private readonly FingerprintFactory $fingerprints,
        private readonly SourceLabelFactory $labels,
    ) {}

    /**
     * @param  list<ArchiveEntryReport>  $entries
     * @return list<Document> die neu erzeugten Dokumente
     *
     * @throws UploadRejectedException
     */
    public function __invoke(
        Document $archiveDocument,
        TemporaryUpload $archiveUpload,
        array $entries,
        UploadLimits $limits,
    ): array {
        $prefix = $archiveUpload->getAttribute('storage_key');

        if (! is_string($prefix) || $prefix === '') {
            throw UploadRejectedException::because(UploadErrorCode::QUELLE_NICHT_VORHANDEN);
        }

        $archivePath = $this->storage->absolutePath($this->storage->originalKey($prefix));

        $archive = new ZipArchive;

        if ($archive->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw UploadRejectedException::because(UploadErrorCode::STRUKTUR_UNGUELTIG);
        }

        $created = [];

        try {
            foreach ($entries as $entry) {
                $created[] = $this->createDocumentFromEntry($archive, $archiveDocument, $entry, $limits);
            }
        } finally {
            $archive->close();
        }

        return $created;
    }

    /**
     * @throws UploadRejectedException
     */
    private function createDocumentFromEntry(
        ZipArchive $archive,
        Document $archiveDocument,
        ArchiveEntryReport $entry,
        UploadLimits $limits,
    ): Document {
        $entryPrefix = $this->storage->newPrefix();
        $targetKey = $this->storage->originalKey($entryPrefix);

        $byteSize = $this->extractEntry($archive, $entry->index, $targetKey, $limits);

        $inspection = $this->mimeGuard->inspectFile(
            $this->storage->absolutePath($targetKey),
            $entry->extension,
            $limits,
        );

        $sequence = $this->nextSequenceNumber($archiveDocument);

        $document = new Document;

        $document->fill([
            'organization_id' => $archiveDocument->getAttribute('organization_id'),
            'billing_run_id' => $archiveDocument->getAttribute('billing_run_id'),
            'sequence_number' => $sequence,
            'source_label' => $this->labels->truncate($this->labels->pending($sequence)),
            'document_type' => DocumentType::SONSTIGES,
            'mime_type' => $inspection->mimeType,
            'original_byte_size' => $byteSize,
            'page_count' => $inspection->pageCount,
            'fingerprint_hmac' => $this->fingerprints->forFile($this->storage->absolutePath($targetKey)),
            'processing_status' => DocumentProcessingStatus::SICHERHEITSPRUEFUNG,
            'security_checked_at' => Carbon::now(),
            'malware_scanner_driver' => $archiveDocument->getAttribute('malware_scanner_driver'),
            'malware_scan_clean' => $archiveDocument->getAttribute('malware_scan_clean'),
            'deletion_status' => DeletionStatus::OFFEN,
        ]);

        $document->save();

        $now = Carbon::now();

        $upload = new TemporaryUpload;

        $upload->fill([
            'organization_id' => $document->getAttribute('organization_id'),
            'document_id' => $document->getKey(),
            'storage_disk' => TemporaryUploadStorage::DISK,
            'storage_key' => $entryPrefix,
            'byte_size' => $byteSize,
            'total_chunks' => 1,
            'received_chunks' => 1,
            'received_bytes' => $byteSize,
            'first_chunk_at' => $now,
            'expires_at' => $now->copy()->addMinutes($this->ttlMinutes()),
            'deletion_attempts' => 0,
            'is_tombstone' => false,
            'provider_deletion_status' => DeletionStatus::NICHT_ERFORDERLICH,
        ]);

        $upload->save();

        $relation = new DocumentRelation;

        $relation->fill([
            'organization_id' => $document->getAttribute('organization_id'),
            'billing_run_id' => $document->getAttribute('billing_run_id'),
            'from_document_id' => $document->getKey(),
            'to_document_id' => $archiveDocument->getKey(),
            'relation_type' => DocumentRelationType::ANLAGE_ZU_HAUPTDOKUMENT,
            'note' => 'Aus einem hochgeladenen Archiv entpackt.',
        ]);

        $relation->save();

        return $document;
    }

    /**
     * Entpackt einen Eintrag blockweise in den Quarantaenebereich. Der Inhalt
     * wird nicht als Ganzes in den Speicher geladen.
     *
     * @throws UploadRejectedException
     */
    private function extractEntry(ZipArchive $archive, int $index, string $targetKey, UploadLimits $limits): int
    {
        $source = $archive->getStreamIndex($index);

        if ($source === false) {
            throw UploadRejectedException::because(UploadErrorCode::ARCHIV_EINTRAG_UNZULAESSIG);
        }

        $this->storage->disk()->put($targetKey, '');

        $target = fopen($this->storage->absolutePath($targetKey), 'wb');

        if ($target === false) {
            fclose($source);

            throw new RuntimeException('Der Archiveintrag konnte nicht in den Kurzzeitbereich geschrieben werden.');
        }

        $written = 0;

        try {
            while (! feof($source)) {
                $block = fread($source, 1024 * 1024);

                if ($block === false || $block === '') {
                    continue;
                }

                $written += (int) fwrite($target, $block);

                if ($written > $limits->maxFileBytes) {
                    throw UploadRejectedException::withContext(UploadErrorCode::ARCHIV_ZIP_BOMBE, [
                        'entpackt' => $written,
                        'limit' => $limits->maxFileBytes,
                    ]);
                }
            }
        } catch (UploadRejectedException $exception) {
            fclose($target);
            fclose($source);
            $this->storage->disk()->delete($targetKey);

            throw $exception;
        }

        fclose($target);
        fclose($source);

        return $written;
    }

    private function nextSequenceNumber(Document $archiveDocument): int
    {
        $max = Document::query()
            ->where('billing_run_id', $archiveDocument->getAttribute('billing_run_id'))
            ->max('sequence_number');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    private function ttlMinutes(): int
    {
        $value = config('smartabrechnen.retention.temp_upload_ttl_minutes');
        $minutes = is_numeric($value) ? (int) $value : 120;

        return min(120, max(1, $minutes));
    }
}
