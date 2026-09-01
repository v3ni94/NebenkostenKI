<?php

declare(strict_types=1);

namespace App\Services\Pdf\Store;

use App\Enums\GeneratedDocumentStatus;
use App\Models\GeneratedDocument;
use App\Services\Pdf\PdfDocument;
use App\Services\Storage\ArtifactStorage;
use Illuminate\Support\Carbon;

/**
 * Schreibt ein erzeugtes PDF dauerhaft weg und protokolliert die
 * Nachweisangaben (Abschnitt 3.6).
 *
 * Je Datei werden SHA-256, Dateigröße, Seitenzahl, Erzeugungszeit,
 * Templateversion und Calculation-Snapshot-ID gespeichert. Der Weg in die
 * Ablage führt ausschließlich über ArtifactStorage; ein Originalupload kann
 * hier technisch nicht landen, weil nur ein ArtifactType übergeben werden
 * kann und die Magic Bytes geprüft werden.
 *
 * Ein finalisiertes PDF wird niemals überschrieben. Eine Korrektur erzeugt
 * einen neuen Eintrag; der alte Eintrag wird über markReplaced() auf ERSETZT
 * gesetzt (Abschnitt 11.5).
 */
final class GeneratedDocumentWriter
{
    public function __construct(private readonly ArtifactStorage $storage = new ArtifactStorage) {}

    public function store(PdfDocument $document, DocumentOwnership $ownership): StoredDocument
    {
        $reference = $this->storage->put(
            $document->artifactType,
            $ownership->organizationId,
            $document->contents,
        );

        $record = GeneratedDocument::create([
            'organization_id' => $ownership->organizationId,
            'billing_run_id' => $ownership->billingRunId,
            'unit_statement_id' => $ownership->unitStatementId,
            'invoice_id' => $ownership->invoiceId,
            'calculation_snapshot_id' => $document->calculationSnapshotId,
            'kind' => $document->artifactType->kind(),
            'variant' => $document->variant,
            'status' => GeneratedDocumentStatus::AKTIV,
            'storage_disk' => $reference->disk,
            'storage_path' => $reference->path,
            'byte_size' => $reference->byteSize,
            'sha256' => $reference->sha256,
            'page_count' => $document->pageCount,
            'template_version' => $document->templateVersion,
            'generated_at' => Carbon::instance($document->generatedAt),
        ]);

        return new StoredDocument($reference, $record);
    }

    /**
     * Setzt einen früheren Eintrag auf ERSETZT, ohne die Datei zu verändern.
     */
    public function markReplaced(GeneratedDocument $previous, GeneratedDocument $replacement): void
    {
        $previous->forceFill([
            'status' => GeneratedDocumentStatus::ERSETZT,
            'replaced_by_document_id' => $replacement->id,
        ])->save();
    }
}
