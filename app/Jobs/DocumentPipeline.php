<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Models\ProcessingJob;
use App\Services\Queue\DatabaseJobQueue;

/**
 * Einstiegspunkt zum Einstellen der Teiljobs des Dokumentlebenszyklus.
 *
 * Die Methoden kapseln den Payload, damit an keiner Aufrufstelle versehentlich
 * mehr als eine Referenz-ID und ein technischer Parameter in die Queue gelangt.
 */
final class DocumentPipeline
{
    public function __construct(private readonly DatabaseJobQueue $queue) {}

    /**
     * @param  string  $extension  angekuendigte Dateiendung, technischer Parameter.
     *                             Bewusst nur die Endung, niemals der Dateiname.
     */
    public function queueAssembly(Document $document, string $extension): ProcessingJob
    {
        return $this->push(DocumentJobType::ZUSAMMENSETZEN, $document, ['erweiterung' => $extension]);
    }

    public function queueClassification(Document $document): ProcessingJob
    {
        return $this->push(DocumentJobType::KLASSIFIZIEREN, $document);
    }

    public function queueExtraction(Document $document): ProcessingJob
    {
        return $this->push(DocumentJobType::EXTRAHIEREN, $document);
    }

    public function queueSourceDeletion(Document $document): ProcessingJob
    {
        return $this->push(DocumentJobType::QUELLEN_LOESCHEN, $document);
    }

    /**
     * @param  array<string, bool|int|string>  $payload
     */
    private function push(DocumentJobType $type, Document $document, array $payload = []): ProcessingJob
    {
        return $this->queue->pushOnce(
            $type->value,
            (string) $document->getKey(),
            array_merge(['dokument_id' => (string) $document->getKey()], $payload),
            $this->stringOrNull($document->getAttribute('organization_id')),
            $this->stringOrNull($document->getAttribute('billing_run_id')),
            $type->priority(),
            $type->maxAttempts(),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
