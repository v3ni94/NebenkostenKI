<?php

declare(strict_types=1);

namespace App\Application\Privacy\Dto;

use App\Models\GeneratedDocument;

/**
 * Ergebnis eines DSGVO-Datenexports.
 *
 * Der Export ist selbst ein erzeugtes Ergebnisartefakt und wird deshalb wie
 * jedes andere Artefakt in generated_documents geführt. Die Auslieferung
 * erfolgt ausschließlich über eine autorisierte Route oder einen kurzlebigen
 * signierten Link, niemals über einen öffentlichen Pfad.
 */
final class DataExportResult
{
    /**
     * @param  list<string>  $entries  Einträge im ZIP, in der Reihenfolge der Aufnahme
     * @param  array<string, int>  $recordCounts  Anzahl exportierter Datensätze je Entität
     */
    public function __construct(
        public readonly GeneratedDocument $document,
        public readonly int $byteSize,
        public readonly array $entries,
        public readonly array $recordCounts,
    ) {}

    public function summary(): string
    {
        return sprintf(
            'Datenexport erzeugt: %d Einträge, %d Byte.',
            count($this->entries),
            $this->byteSize,
        );
    }
}
