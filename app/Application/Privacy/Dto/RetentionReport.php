<?php

declare(strict_types=1);

namespace App\Application\Privacy\Dto;

/**
 * Ergebnis eines Aufbewahrungslaufs.
 *
 * Sind die Fristen nicht gesetzt, wird ausdrücklich nichts gelöscht. Der Lauf
 * meldet das als offenen Punkt, damit die Festlegung vor Livegang nicht
 * untergeht.
 */
final class RetentionReport
{
    public function __construct(
        public readonly ?int $extractedDataDays,
        public readonly ?int $generatedPdfDays,
        public readonly int $deletedExtractedFields = 0,
        public readonly int $deletedDocumentPages = 0,
        public readonly int $deletedGeneratedDocuments = 0,
        public readonly int $deletedArtifacts = 0,
        public readonly int $failedArtifacts = 0,
    ) {}

    public function extractedDataConfigured(): bool
    {
        return $this->extractedDataDays !== null && $this->extractedDataDays > 0;
    }

    public function generatedPdfConfigured(): bool
    {
        return $this->generatedPdfDays !== null && $this->generatedPdfDays > 0;
    }

    public function fullyConfigured(): bool
    {
        return $this->extractedDataConfigured() && $this->generatedPdfConfigured();
    }

    public function summary(): string
    {
        return sprintf(
            'Aufbewahrung: %d Extraktionsfelder, %d Dokumentseiten, %d erzeugte PDFs gelöscht, '
            .'%d Artefaktdateien entfernt, %d Fehler.',
            $this->deletedExtractedFields,
            $this->deletedDocumentPages,
            $this->deletedGeneratedDocuments,
            $this->deletedArtifacts,
            $this->failedArtifacts,
        );
    }
}
