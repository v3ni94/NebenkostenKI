<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Dto\AssemblyResult;
use App\Enums\DocumentProcessingStatus;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Storage\ArchiveGuard;
use App\Services\Storage\ChunkAssembler;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\FileCategory;
use App\Services\Storage\FileInspection;
use App\Services\Storage\FingerprintFactory;
use App\Services\Storage\HeicConverter;
use App\Services\Storage\MalwareScannerFactory;
use App\Services\Storage\MimeGuard;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use App\Services\Storage\UploadLimits;
use Illuminate\Support\Carbon;

/**
 * Use Case: hochgeladene Abschnitte zusammensetzen und die vollstaendige
 * Pruefkette durchlaufen (Abschnitt 6.3 Schritte 1 bis 6).
 *
 * REIHENFOLGE, verbindlich:
 *   1. Abschnitte zusammensetzen, Vollstaendigkeit pruefen
 *   2. MIME per Magic Bytes UND Abgleich mit der angekuendigten Endung
 *   3. Groesse und Struktur (PDF-Trailer, Bild-Header, ZIP-Zentralverzeichnis)
 *   4. Malwarepruefung ueber den konfigurierten Adapter
 *   5. HEIC-Umwandlung, sofern ein Konverter vorhanden ist
 *   6. Archive eintragsweise pruefen und aufloesen
 *   7. Fingerabdruck bilden und Dubletten erkennen
 *   8. Seitenzahl und minimal noetige technische Metadaten sichern
 *
 * DATENSCHUTZ:
 * - Es wird ausschliesslich auf der Disk temporary_uploads gearbeitet.
 * - Der Originaldateiname erreicht diesen Use Case nicht; uebergeben wird nur
 *   die angekuendigte Endung als technischer Parameter.
 * - Der reine SHA-256 wird nur fluechtig gebildet, dauerhaft gespeichert wird
 *   allein der schluesselgebundene HMAC (siehe FingerprintFactory).
 * - EXIF-Daten werden nicht ausgelesen; die HEIC-Umwandlung entfernt sie.
 */
final class AssembleUpload
{
    public function __construct(
        private readonly TemporaryUploadStorage $storage,
        private readonly ChunkAssembler $assembler,
        private readonly MimeGuard $mimeGuard,
        private readonly ArchiveGuard $archiveGuard,
        private readonly MalwareScannerFactory $scanners,
        private readonly HeicConverter $heicConverter,
        private readonly FingerprintFactory $fingerprints,
        private readonly DetectDuplicate $duplicates,
        private readonly ExpandArchive $expandArchive,
    ) {}

    /**
     * @param  string  $declaredExtension  angekuendigte Endung, technischer Parameter
     *
     * @throws UploadRejectedException
     */
    public function __invoke(Document $document, string $declaredExtension, ?UploadLimits $limits = null): AssemblyResult
    {
        $limits ??= UploadLimits::fromConfig();

        $upload = $this->requireUpload($document);
        $prefix = $this->requirePrefix($upload);

        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::SICHERHEITSPRUEFUNG,
        ])->save();

        $assembly = $this->assembler->assemble(
            $prefix,
            (int) $upload->getAttribute('total_chunks'),
            (int) $upload->getAttribute('byte_size'),
            $limits,
        );

        $absolutePath = $this->storage->absolutePath($assembly->storageKey);

        $inspection = $this->mimeGuard->inspectFile($absolutePath, $declaredExtension, $limits);

        $this->scanForMalware($document, $absolutePath);

        $converted = $this->convertIfNeeded($prefix, $absolutePath, $inspection);

        $fingerprint = $this->fingerprints->forFile($absolutePath);

        $document->forceFill([
            'mime_type' => $inspection->mimeType,
            'original_byte_size' => $assembly->byteSize,
            'page_count' => $inspection->pageCount,
            'fingerprint_hmac' => $fingerprint,
            'security_checked_at' => Carbon::now(),
        ])->save();

        $upload->forceFill([
            'byte_size' => $assembly->byteSize,
            'received_bytes' => $assembly->byteSize,
            'received_chunks' => (int) $upload->getAttribute('total_chunks'),
        ])->save();

        // Nur beim erstmaligen Zusammensetzen verbuchen. Ein Wiederanlauf nach
        // abgelaufenem Lease darf das Volumen des Laufs nicht doppelt belasten.
        if (! $assembly->alreadyAssembled) {
            $this->commitQuota($document, $assembly->byteSize, $limits);
        }

        $original = $this->duplicates->findOriginal($document);

        if ($original instanceof Document) {
            $this->duplicates->markAsDuplicate($document, $original);

            return new AssemblyResult($document, $inspection, true, false, [], $converted);
        }

        if ($inspection->category === FileCategory::ARCHIV) {
            $entries = $this->archiveGuard->inspect($absolutePath, $limits);
            $expanded = ($this->expandArchive)($document, $upload, $entries, $limits);

            return new AssemblyResult($document, $inspection, false, true, $expanded, $converted);
        }

        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::KLASSIFIZIERUNG,
        ])->save();

        return new AssemblyResult($document, $inspection, false, false, [], $converted);
    }

    /**
     * @throws UploadRejectedException
     */
    private function requireUpload(Document $document): TemporaryUpload
    {
        $upload = TemporaryUpload::query()
            ->where('document_id', $document->getKey())
            ->where('is_tombstone', false)
            ->first();

        if (! $upload instanceof TemporaryUpload) {
            throw UploadRejectedException::because(UploadErrorCode::QUELLE_NICHT_VORHANDEN);
        }

        $expiresAt = $upload->getAttribute('expires_at');

        if ($expiresAt instanceof Carbon && $expiresAt->isPast()) {
            throw UploadRejectedException::because(UploadErrorCode::UPLOAD_ABGELAUFEN);
        }

        return $upload;
    }

    /**
     * @throws UploadRejectedException
     */
    private function requirePrefix(TemporaryUpload $upload): string
    {
        $prefix = $upload->getAttribute('storage_key');

        if (! is_string($prefix) || $prefix === '') {
            throw UploadRejectedException::because(UploadErrorCode::QUELLE_NICHT_VORHANDEN);
        }

        return $prefix;
    }

    /**
     * @throws UploadRejectedException
     */
    private function scanForMalware(Document $document, string $absolutePath): void
    {
        $scanner = $this->scanners->make();
        $result = $scanner->scanFile($absolutePath);

        $document->forceFill([
            'malware_scanner_driver' => $scanner->driver(),
            // Bei abgeschaltetem Treiber bleibt der Wert null: die Datei wurde
            // ausdruecklich NICHT geprueft. Ein true waere eine Falschaussage.
            'malware_scan_clean' => $result->scanned ? $result->clean : null,
        ])->save();

        if ($result->isBlocking()) {
            throw UploadRejectedException::because(
                $result->errorCode === null
                    ? UploadErrorCode::MALWARE_GEFUNDEN
                    : UploadErrorCode::MALWARE_PRUEFUNG_FEHLGESCHLAGEN
            );
        }
    }

    /**
     * HEIC wird serverseitig nach JPEG gewandelt. Fehlt der Konverter, wird der
     * Upload mit einer klaren deutschen Handlungsanweisung abgelehnt, nicht
     * stillschweigend verworfen (Abschnitt 6.1).
     *
     * @throws UploadRejectedException
     */
    private function convertIfNeeded(string $prefix, string $absolutePath, FileInspection $inspection): bool
    {
        if (! $inspection->category->requiresConversion()) {
            return false;
        }

        $targetKey = $this->storage->convertedImageKey($prefix);

        $this->storage->disk()->put($targetKey, '');

        $this->heicConverter->convertToJpeg($absolutePath, $this->storage->absolutePath($targetKey));

        return true;
    }

    private function commitQuota(Document $document, int $byteSize, UploadLimits $limits): void
    {
        $billingRun = BillingRun::query()->whereKey($document->getAttribute('billing_run_id'))->first();

        if ($billingRun instanceof BillingRun) {
            (new UploadQuota($limits))->commit($billingRun, $byteSize);
        }
    }
}
