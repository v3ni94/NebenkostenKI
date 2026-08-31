<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Ergebnis der Formatpruefung einer Datei.
 *
 * DATENSCHUTZ: Das Objekt fuehrt ausschliesslich technische Merkmale. Es
 * enthaelt keinen Dateiinhalt, keinen Dateinamen und keine EXIF-Daten. EXIF
 * wird bewusst nicht ausgelesen und nicht weitergegeben (Abschnitt 6.4).
 */
final class FileInspection
{
    public function __construct(
        public readonly string $mimeType,
        public readonly FileCategory $category,
        public readonly int $byteSize,
        public readonly ?int $pageCount = null,
        public readonly ?int $imageWidth = null,
        public readonly ?int $imageHeight = null,
    ) {}

    /**
     * Nur Metadaten, damit Debugger und Fehlerseiten keinen Inhalt ausgeben.
     *
     * @return array<string, int|string|null>
     */
    public function toTechnicalContext(): array
    {
        return [
            'mime_type' => $this->mimeType,
            'kategorie' => $this->category->value,
            'byte_size' => $this->byteSize,
            'seiten' => $this->pageCount,
        ];
    }
}
