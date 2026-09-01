<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Ai\Dto\DocumentPayload;
use App\Services\Storage\TemporaryUploadStorage;
use Throwable;

/**
 * Liest die Quelldatei aus dem Kurzzeitbereich und baut daraus die Nutzlast
 * fuer genau einen KI-Aufruf.
 *
 * VERBINDLICHE DATENSCHUTZREGELN:
 *
 * 1. Der Dateiinhalt lebt ausschliesslich im DocumentPayload und damit im
 *    Arbeitsspeicher dieses Laufs. Er wird nicht zwischengespeichert, nicht
 *    protokolliert und nicht in einen Queue-Payload geschrieben; DocumentPayload
 *    sperrt die Serialisierung dafuer ausdruecklich.
 * 2. Der Originaldateiname wird nicht mitgefuehrt. An den Provider geht
 *    ausschliesslich die neutrale Quellenbezeichnung des Dokuments, zum
 *    Beispiel "Dokument 01 - Grundsteuerbescheid".
 * 3. Es wird kein lokal vorextrahierter Volltext uebergeben. Der Provider
 *    erhaelt die Datei, nicht einen zusaetzlich erzeugten OCR-Text, damit kein
 *    weiterer Volltext entsteht, der geloescht werden muesste.
 *
 * HEIC: Die Pipeline legt beim Zusammensetzen eine JPEG-Konvertierung unter
 * demselben Praefix ab. Liegt sie vor, wird sie verwendet, weil die Provider
 * HEIC nicht unterstuetzen.
 */
final class DocumentPayloadFactory
{
    /**
     * Uebersetzung der Uploadmimetypen in die vom Provider unterstuetzten.
     *
     * CSV ist reiner Text und wird als text/plain uebergeben. XLSX bleibt
     * bewusst unuebersetzt: eine Tabellenkalkulationsdatei wird von keinem
     * Provider direkt gelesen, der Aufruf wird deshalb mit einem klaren
     * Fehlercode abgelehnt statt Rohbytes zu senden.
     *
     * @var array<string, string>
     */
    private const MIME_TRANSLATION = [
        'text/csv' => DocumentPayload::MIME_PLAIN_TEXT,
        'text/plain' => DocumentPayload::MIME_PLAIN_TEXT,
    ];

    public function __construct(private readonly TemporaryUploadStorage $storage) {}

    /**
     * Nutzlast des Dokuments oder null, wenn die Quelldatei nicht mehr
     * vorhanden oder nicht lesbar ist.
     */
    public function forUpload(Document $document, TemporaryUpload $upload): ?DocumentPayload
    {
        $prefix = $upload->getAttribute('storage_key');

        if (! is_string($prefix) || $prefix === '') {
            return null;
        }

        $key = $this->resolveKey($prefix);

        if ($key === null) {
            return null;
        }

        try {
            $contents = $this->storage->disk()->get($key);
        } catch (Throwable) {
            // Die Meldung wird bewusst verworfen. Sie koennte den Storage-Key
            // und damit einen Hinweis auf die Ablage enthalten.
            return null;
        }

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $pageCount = $document->getAttribute('page_count');
        $label = $document->getAttribute('source_label');

        return new DocumentPayload(
            is_string($label) && $label !== '' ? $label : 'Unterlage',
            $this->mimeTypeFor($document, $key, $prefix),
            $contents,
            is_int($pageCount) && $pageCount > 0 ? $pageCount : null,
        );
    }

    /**
     * Bevorzugt die Konvertierung, sonst die Originaldatei.
     */
    private function resolveKey(string $prefix): ?string
    {
        $converted = $this->storage->convertedImageKey($prefix);

        if ($this->storage->exists($converted) && $this->storage->size($converted) > 0) {
            return $converted;
        }

        $original = $this->storage->originalKey($prefix);

        return $this->storage->exists($original) ? $original : null;
    }

    private function mimeTypeFor(Document $document, string $key, string $prefix): string
    {
        if ($key === $this->storage->convertedImageKey($prefix)) {
            return DocumentPayload::MIME_JPEG;
        }

        $mimeType = $document->getAttribute('mime_type');
        $mimeType = is_string($mimeType) ? strtolower(trim($mimeType)) : '';

        return self::MIME_TRANSLATION[$mimeType] ?? $mimeType;
    }
}
