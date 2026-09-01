<?php

declare(strict_types=1);

namespace App\Services\Pdf\Store;

use App\Enums\GeneratedDocumentVariant;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfException;
use App\Services\Storage\ArtifactType;
use RuntimeException;
use ZipArchive;

/**
 * Sammeldownload als ZIP-Paket (Abschnitt 3.6).
 *
 * Aufgenommen werden ausschließlich vom System erzeugte PDFs. Das Paket wird
 * im temporären Verzeichnis zusammengesetzt und anschließend als Bytefolge
 * zurückgegeben; die dauerhafte Ablage erfolgt über ArtifactStorage.
 *
 * Vorschau und Finalversion werden nie im selben Paket gemischt, damit ein
 * Wasserzeichendokument nicht versehentlich als Finalversion versendet wird.
 */
final class DocumentPackageBuilder
{
    /**
     * @param  list<PdfDocument>  $documents
     * @return array{contents: string, entries: list<string>}
     */
    public function build(array $documents): array
    {
        if ($documents === []) {
            throw new RuntimeException('Ein ZIP-Paket ohne Dokumente wird nicht erzeugt.');
        }

        $this->assertSingleVariant($documents);

        $tempFile = tempnam(sys_get_temp_dir(), 'sa-paket-');

        if ($tempFile === false) {
            throw new RuntimeException('Für das ZIP-Paket konnte keine temporäre Datei angelegt werden.');
        }

        $zip = new ZipArchive();

        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempFile);

            throw new RuntimeException('Das ZIP-Paket konnte nicht geöffnet werden.');
        }

        $entries = [];

        foreach ($documents as $index => $document) {
            if (! $document->isPdf()) {
                $zip->close();
                @unlink($tempFile);

                throw PdfException::invalidOutput('zip-paket');
            }

            $name = $this->entryName($document, $index, $entries);
            $zip->addFromString($name, $document->contents);
            $entries[] = $name;
        }

        $zip->close();

        $contents = file_get_contents($tempFile);
        @unlink($tempFile);

        if ($contents === false || ! str_starts_with($contents, "PK\x03\x04")) {
            throw new RuntimeException('Das ZIP-Paket wurde nicht gültig erzeugt.');
        }

        return ['contents' => $contents, 'entries' => $entries];
    }

    public function artifactType(): ArtifactType
    {
        return ArtifactType::ZIP_PAKET;
    }

    /**
     * @param  list<PdfDocument>  $documents
     */
    private function assertSingleVariant(array $documents): void
    {
        $variants = [];

        foreach ($documents as $document) {
            $variants[$document->variant->value] = true;
        }

        if (count($variants) > 1) {
            throw new RuntimeException('Vorschau und Finalversion werden nicht im selben ZIP-Paket ausgeliefert.');
        }
    }

    /**
     * @param  list<string>  $existing
     */
    private function entryName(PdfDocument $document, int $index, array $existing): string
    {
        $base = $document->downloadName ?? sprintf(
            '%s-%s.pdf',
            strtolower($document->artifactType->value),
            $document->variant === GeneratedDocumentVariant::VORSCHAU ? 'vorschau' : 'final',
        );

        if (! in_array($base, $existing, true)) {
            return $base;
        }

        return sprintf('%02d-%s', $index + 1, $base);
    }
}
