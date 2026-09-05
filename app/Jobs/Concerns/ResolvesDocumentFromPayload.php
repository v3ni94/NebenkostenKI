<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Document;
use App\Models\ProcessingJob;

/**
 * Liest die Referenz-IDs aus einem Queue-Payload.
 *
 * Der Payload enthaelt ausschliesslich IDs und technische Parameter. Alle
 * fachlichen Daten werden ueber die ID nachgeladen, niemals aus dem Payload
 * uebernommen.
 */
trait ResolvesDocumentFromPayload
{
    protected function documentFrom(ProcessingJob $job): ?Document
    {
        $payload = $job->getAttribute('payload');
        $documentId = is_array($payload) ? ($payload['dokument_id'] ?? null) : null;

        if (! is_string($documentId) || $documentId === '') {
            $documentId = $job->getAttribute('document_id');
        }

        if (! is_string($documentId) || $documentId === '') {
            return null;
        }

        $document = Document::query()->whereKey($documentId)->first();

        return $document instanceof Document ? $document : null;
    }

    protected function stringFromPayload(ProcessingJob $job, string $key, string $default = ''): string
    {
        $payload = $job->getAttribute('payload');
        $value = is_array($payload) ? ($payload[$key] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
