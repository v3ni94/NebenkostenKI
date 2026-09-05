<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Enums\GeneratedDocumentVariant;
use App\Services\Storage\ArtifactType;
use DateTimeImmutable;

/**
 * Ergebnis einer PDF-Erzeugung mit allen Nachweisangaben (Abschnitt 3.6).
 *
 * Gespeichert werden je Datei SHA-256, Dateigröße, Erzeugungszeit,
 * Templateversion und die Calculation-Snapshot-ID. Die Bytes werden
 * ausschließlich über ArtifactStorage dauerhaft abgelegt.
 */
final readonly class PdfDocument
{
    public function __construct(
        public ArtifactType $artifactType,
        public GeneratedDocumentVariant $variant,
        public string $contents,
        public int $pageCount,
        public string $templateVersion,
        public DateTimeImmutable $generatedAt,
        public ?string $calculationSnapshotId = null,
        public ?string $downloadName = null,
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->contents);
    }

    public function byteSize(): int
    {
        return strlen($this->contents);
    }

    public function isPdf(): bool
    {
        return str_starts_with($this->contents, '%PDF-');
    }

    public function hasWatermarkVariant(): bool
    {
        return $this->variant === GeneratedDocumentVariant::VORSCHAU;
    }
}
