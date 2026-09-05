<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Enums\GeneratedDocumentVariant;

/**
 * Alle Dokumente eines Abrechnungslaufs in einer Variante.
 *
 * Vorschau und Finalversion werden getrennt gespeichert und niemals gemischt
 * (Abschnitt 14.3).
 */
final readonly class PdfDocumentSet
{
    /**
     * @param  list<PdfDocument>  $statements  Mieterabrechnungen samt Anlagen
     * @param  list<PdfDocument>  $taxBenefitAttachments  Anlagen nach § 35a EStG
     */
    public function __construct(
        public GeneratedDocumentVariant $variant,
        public array $statements,
        public array $taxBenefitAttachments = [],
        public ?PdfDocument $ownerOverview = null,
    ) {}

    /**
     * Alle Dokumente in Ausgabereihenfolge.
     *
     * @return list<PdfDocument>
     */
    public function all(): array
    {
        $documents = [...$this->statements, ...$this->taxBenefitAttachments];

        if ($this->ownerOverview instanceof PdfDocument) {
            $documents[] = $this->ownerOverview;
        }

        return $documents;
    }

    public function count(): int
    {
        return count($this->all());
    }

    public function totalPages(): int
    {
        $pages = 0;

        foreach ($this->all() as $document) {
            $pages += $document->pageCount;
        }

        return $pages;
    }
}
