<?php

declare(strict_types=1);

namespace App\Services\Pdf\Store;

use App\Models\GeneratedDocument;
use App\Services\Storage\ArtifactReference;

/**
 * Ergebnis einer dauerhaften Ablage: Storage-Referenz und der zugehörige
 * Datenbankeintrag mit den Nachweisangaben.
 */
final readonly class StoredDocument
{
    public function __construct(
        public ArtifactReference $artifact,
        public GeneratedDocument $record,
    ) {}
}
