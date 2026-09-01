<?php

declare(strict_types=1);

namespace App\Application\Wizard\Dto;

use App\Enums\GeneratedDocumentKind;
use App\Models\GeneratedDocument;

/**
 * Ein Dokument der Vorschau für den Inline-Viewer.
 *
 * Jede Vorschau trägt ein serverseitig eingebranntes Wasserzeichen. Ein
 * Download in dieser Phase ist ausschließlich mit Wasserzeichen möglich.
 */
final readonly class PreviewDocumentView
{
    public function __construct(
        public GeneratedDocument $document,
        public string $titel,
        public string $untertitel,
        public int $seiten,
    ) {}

    public function id(): string
    {
        return (string) $this->document->getKey();
    }

    public function art(): GeneratedDocumentKind
    {
        return $this->document->kind;
    }

    public function istEigentuemeruebersicht(): bool
    {
        return $this->document->kind === GeneratedDocumentKind::EIGENTUEMERUEBERSICHT;
    }
}
