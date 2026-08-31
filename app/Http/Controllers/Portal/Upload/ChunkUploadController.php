<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Upload;

use App\Application\Documents\AcceptChunk;
use App\Application\Documents\Dto\StartUploadCommand;
use App\Application\Documents\StartUpload;
use App\Enums\DocumentProcessingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Upload\CompleteUploadRequest;
use App\Http\Requests\Upload\StartUploadRequest;
use App\Http\Requests\Upload\StoreChunkRequest;
use App\Jobs\DocumentPipeline;
use App\Models\BillingRun;
use App\Models\TemporaryUpload;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Chunk-Upload der Anwendung.
 *
 * WARUM CHUNKS: Auf IONOS Webhosting sind post_max_size und
 * upload_max_filesize nicht verlaesslich hoch konfigurierbar. Der Browser
 * uebertraegt deshalb kleine Abschnitte, die serverseitig wieder
 * zusammengesetzt werden (Abschnitt 6.1).
 *
 * DATENSCHUTZ:
 * - Der Originaldateiname wird nur zur Bestimmung der Endung entgegengenommen
 *   und sofort verworfen. Er wird nicht gespeichert und nicht protokolliert.
 * - Der temporaere Storage-Key wird niemals an den Browser gegeben.
 * - Abschnitte landen ausschliesslich auf der Disk temporary_uploads.
 *
 * AUTORISIERUNG: objektbezogen ueber die Policy des Abrechnungslaufs, niemals
 * allein anhand der URL-ID. Ein fremder Mandant erhaelt 403 beziehungsweise
 * 404, auch bei Kenntnis der ID.
 */
class ChunkUploadController extends Controller
{
    public function __construct(
        private readonly StartUpload $startUpload,
        private readonly AcceptChunk $acceptChunk,
        private readonly TemporaryUploadStorage $storage,
        private readonly DocumentPipeline $pipeline,
    ) {}

    /**
     * Startet einen Upload und liefert Abschnittsgroesse und Anzahl zurueck.
     */
    public function store(StartUploadRequest $request, BillingRun $billingRun): JsonResponse
    {
        Gate::authorize('update', $billingRun);

        try {
            $session = ($this->startUpload)(
                $billingRun,
                new StartUploadCommand(
                    $request->fileExtension(),
                    $request->declaredByteSize(),
                    $request->suggestedType(),
                ),
            );
        } catch (UploadRejectedException $exception) {
            return $this->rejected($exception);
        }

        return response()->json($session->toArray(), 201);
    }

    /**
     * Nimmt einen Dateiabschnitt an. Idempotent: ein doppelt gesendeter
     * Abschnitt aendert nichts.
     */
    public function storeChunk(StoreChunkRequest $request, TemporaryUpload $upload): JsonResponse
    {
        $this->authorizeUpload($upload);

        try {
            $result = ($this->acceptChunk)(
                $upload,
                $request->chunkIndex(),
                $request->chunkFile()->getRealPath(),
            );
        } catch (UploadRejectedException $exception) {
            return $this->rejected($exception);
        }

        return response()->json($result->toArray());
    }

    /**
     * Schliesst den Upload ab und stellt die Verarbeitung ein.
     *
     * Die Zusammensetzung und die Pruefkette laufen als Teiljob, damit die
     * Anfrage nicht in die Prozesslaufzeit des Webhostings laeuft. Die
     * Oberflaeche zeigt den Verarbeitungsstand und stellt die tatsaechliche
     * Cron-Auflösung ehrlich dar (ADR-006).
     */
    public function complete(CompleteUploadRequest $request, TemporaryUpload $upload): JsonResponse
    {
        $this->authorizeUpload($upload);

        $prefix = $upload->getAttribute('storage_key');
        $totalChunks = (int) $upload->getAttribute('total_chunks');

        if (! is_string($prefix) || $prefix === '' || $upload->getAttribute('is_tombstone') === true) {
            return $this->rejected(UploadRejectedException::because(UploadErrorCode::UPLOAD_ABGELAUFEN));
        }

        $missing = $this->storage->missingChunkIndexes($prefix, $totalChunks);

        if ($missing !== []) {
            return response()->json([
                'fehlercode' => UploadErrorCode::CHUNK_FEHLT->value,
                'meldung' => UploadErrorCode::CHUNK_FEHLT->message(),
                'fehlende_abschnitte' => $missing,
            ], 422);
        }

        $document = $upload->document;

        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::SICHERHEITSPRUEFUNG,
        ])->save();

        $job = $this->pipeline->queueAssembly($document, $request->fileExtension());

        return response()->json([
            'dokument_id' => (string) $document->getKey(),
            'quellenbezeichnung' => (string) $document->getAttribute('source_label'),
            'status' => DocumentProcessingStatus::SICHERHEITSPRUEFUNG->value,
            'statustext' => DocumentProcessingStatus::SICHERHEITSPRUEFUNG->label(),
            'teiljob_id' => (string) $job->getKey(),
        ], 202);
    }

    /**
     * Objektbezogene Autorisierung ueber den zugehoerigen Abrechnungslauf.
     */
    private function authorizeUpload(TemporaryUpload $upload): void
    {
        $document = $upload->document;

        abort_unless($document !== null, 404);

        $billingRun = $document->billingRun;

        abort_unless($billingRun instanceof BillingRun, 404);

        Gate::authorize('update', $billingRun);
    }

    private function rejected(UploadRejectedException $exception): JsonResponse
    {
        return response()->json([
            'fehlercode' => $exception->errorCode->value,
            'meldung' => $exception->getMessage(),
        ], 422);
    }
}
