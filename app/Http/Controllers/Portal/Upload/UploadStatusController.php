<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Upload;

use App\Enums\DeletionStatus;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadLimits;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Uploadzone und Verarbeitungsstand.
 *
 * DATENSCHUTZ: Ausgegeben werden ausschliesslich die neutrale
 * Quellenbezeichnung, Dokumentart, Verarbeitungsstand, Seitenzahl und
 * Loeschstatus. Niemals ein Dateiname, ein Storage-Key oder ein Vorschaubild
 * der Quelldatei. Es gibt bewusst keinen Abruf der Originaldatei, auch nicht
 * fuer den Inhaber (DocumentPolicy::downloadOriginal gibt immer false zurueck).
 */
class UploadStatusController extends Controller
{
    public function __construct(private readonly TemporaryUploadStorage $storage) {}

    /**
     * Uploadzone mit Statusliste.
     */
    public function show(BillingRun $billingRun): View
    {
        Gate::authorize('update', $billingRun);

        $limits = UploadLimits::fromConfig();

        return view('portal.upload.index', [
            'billingRun' => $billingRun,
            'dokumente' => $this->documentsFor($billingRun),
            'maxDateiMb' => $limits->maxFileMegabytes(),
            'maxLaufMb' => $limits->maxRunMegabytes(),
            'abschnittsgroesse' => $limits->chunkBytes,
            'aufbewahrungMinuten' => $this->ttlMinutes(),
            'kategorien' => DocumentType::cases(),
        ]);
    }

    /**
     * Verarbeitungsstand aller Dokumente eines Abrechnungslaufs.
     */
    public function index(BillingRun $billingRun): JsonResponse
    {
        Gate::authorize('update', $billingRun);

        return response()->json([
            'dokumente' => $this->documentsFor($billingRun),
        ]);
    }

    /**
     * Zustand eines laufenden Uploads. Der Browser setzt damit nach einem
     * Abbruch genau bei den fehlenden Abschnitten fort.
     */
    public function upload(TemporaryUpload $upload): JsonResponse
    {
        $document = $upload->document;

        abort_unless($document instanceof Document, 404);

        $billingRun = $document->billingRun;

        abort_unless($billingRun instanceof BillingRun, 404);

        Gate::authorize('update', $billingRun);

        $prefix = $upload->getAttribute('storage_key');
        $totalChunks = (int) $upload->getAttribute('total_chunks');

        $missing = is_string($prefix) && $prefix !== ''
            ? $this->storage->missingChunkIndexes($prefix, $totalChunks)
            : range(0, max(0, $totalChunks - 1));

        return response()->json([
            'upload_id' => (string) $upload->getKey(),
            'dokument_id' => (string) $document->getKey(),
            'abschnitte' => $totalChunks,
            'fehlende_abschnitte' => array_values($missing),
            'vollstaendig' => $missing === [],
            'abgelaufen' => $upload->getAttribute('is_tombstone') === true,
        ]);
    }

    /**
     * @return list<array<string, bool|int|string|null>>
     */
    private function documentsFor(BillingRun $billingRun): array
    {
        $documents = Document::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->orderBy('sequence_number')
            ->get();

        $rows = [];

        foreach ($documents as $document) {
            $status = $document->getAttribute('processing_status');
            $deletion = $document->getAttribute('deletion_status');
            $type = $document->getAttribute('document_type');

            $rows[] = [
                'id' => (string) $document->getKey(),
                'nummer' => (int) $document->getAttribute('sequence_number'),
                'quellenbezeichnung' => (string) $document->getAttribute('source_label'),
                'dokumentart' => $type instanceof DocumentType ? $type->label() : null,
                'status' => $status instanceof DocumentProcessingStatus ? $status->value : null,
                'statustext' => $status instanceof DocumentProcessingStatus ? $status->label() : null,
                'seiten' => $document->getAttribute('page_count'),
                'loeschstatus' => $deletion instanceof DeletionStatus ? $deletion->value : null,
                'loeschstatustext' => $deletion instanceof DeletionStatus ? $deletion->label() : null,
                'original_geloescht' => $document->getAttribute('original_deleted_at') !== null,
                'dublette' => $document->getAttribute('duplicate_of_document_id') !== null,
                'fehlermeldung' => $document->getAttribute('failure_message'),
            ];
        }

        return $rows;
    }

    private function ttlMinutes(): int
    {
        $value = config('smartabrechnen.retention.temp_upload_ttl_minutes');
        $minutes = is_numeric($value) ? (int) $value : 120;

        return min(120, max(1, $minutes));
    }
}
