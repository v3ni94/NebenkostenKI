<?php

declare(strict_types=1);

namespace App\Application\Documents\Dto;

use App\Models\Document;
use App\Services\Storage\FileInspection;

/**
 * Ergebnis der Zusammensetzung und der vollstaendigen Pruefkette.
 */
final class AssemblyResult
{
    /**
     * @param  list<Document>  $archiveDocuments  aus einem Archiv entpackte Dokumente
     */
    public function __construct(
        public readonly Document $document,
        public readonly FileInspection $inspection,
        public readonly bool $duplicate,
        public readonly bool $archiveExpanded = false,
        public readonly array $archiveDocuments = [],
        public readonly bool $converted = false,
    ) {}
}
