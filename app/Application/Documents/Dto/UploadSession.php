<?php

declare(strict_types=1);

namespace App\Application\Documents\Dto;

use App\Models\Document;
use App\Models\TemporaryUpload;
use Illuminate\Support\Carbon;

/**
 * Antwort auf den Start eines Chunk-Uploads.
 *
 * Der Browser erhaelt daraus die Abschnittsgroesse, die Anzahl der Abschnitte
 * und den spaetesten Loeschzeitpunkt. Der temporaere Storage-Key wird niemals
 * an den Browser gegeben.
 */
final class UploadSession
{
    public function __construct(
        public readonly Document $document,
        public readonly TemporaryUpload $upload,
        public readonly int $totalChunks,
        public readonly int $chunkBytes,
        public readonly Carbon $expiresAt,
    ) {}

    /**
     * @return array<string, bool|int|string>
     */
    public function toArray(): array
    {
        return [
            'upload_id' => (string) $this->upload->getKey(),
            'dokument_id' => (string) $this->document->getKey(),
            'quellenbezeichnung' => (string) $this->document->getAttribute('source_label'),
            'abschnitte' => $this->totalChunks,
            'abschnittsgroesse' => $this->chunkBytes,
            'geloescht_spaetestens' => $this->expiresAt->toIso8601String(),
        ];
    }
}
