<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

use LogicException;
use SensitiveParameter;

/**
 * Eine Quelldatei fuer genau einen Verarbeitungslauf.
 *
 * VERBINDLICHE DATENSCHUTZREGELN (Grundsaetze 3 und 4, Abschnitte 6.3 und
 * 6.4):
 *
 * 1. Der Dateiinhalt lebt ausschliesslich im Arbeitsspeicher dieses Objekts.
 *    Er wird nicht dauerhaft gespeichert, nicht geloggt, nicht in Ausnahmen
 *    eingebettet und nicht in Queue-Payloads geschrieben.
 * 2. __debugInfo() liefert nur Metadaten, damit var_dump, Debugger und
 *    Fehlerseiten den Inhalt nicht ausgeben.
 * 3. __serialize() wirft absichtlich eine Ausnahme. Ein Serialisieren waere
 *    der Weg, ueber den ein Dateiinhalt in einen Queue-Payload oder Cache
 *    gelangt. Das ist gesperrt.
 * 4. Der Originaldateiname wird nicht gefuehrt. Dauerhaft und in Protokollen
 *    gilt nur die neutrale Quellenbezeichnung, zum Beispiel
 *    "Dokument 01 - Grundsteuerbescheid".
 * 5. Optional uebergebener Text aus einer lokalen Vorextraktion ist ebenfalls
 *    nur fuer diesen Lauf bestimmt. Vollstaendiger OCR-Text wird niemals
 *    dauerhaft gespeichert.
 */
final class DocumentPayload
{
    public const MIME_PDF = 'application/pdf';

    public const MIME_PNG = 'image/png';

    public const MIME_JPEG = 'image/jpeg';

    public const MIME_WEBP = 'image/webp';

    public const MIME_GIF = 'image/gif';

    public const MIME_PLAIN_TEXT = 'text/plain';

    public function __construct(
        public readonly string $neutralLabel,
        public readonly string $mimeType,
        #[SensitiveParameter]
        private readonly string $contents,
        public readonly ?int $pageCount = null,
        #[SensitiveParameter]
        private readonly ?string $preExtractedText = null,
    ) {}

    /**
     * Rohinhalt der Datei. Nur fuer den Providerrequest bestimmt.
     */
    public function contents(): string
    {
        return $this->contents;
    }

    /**
     * Lokal vorextrahierter Text, soweit vorhanden. Nur fuer den
     * Providerrequest bestimmt.
     */
    public function preExtractedText(): ?string
    {
        return $this->preExtractedText;
    }

    public function base64(): string
    {
        return base64_encode($this->contents);
    }

    public function byteSize(): int
    {
        return strlen($this->contents);
    }

    public function isPdf(): bool
    {
        return $this->mimeType === self::MIME_PDF;
    }

    public function isImage(): bool
    {
        return in_array($this->mimeType, [
            self::MIME_PNG,
            self::MIME_JPEG,
            self::MIME_WEBP,
            self::MIME_GIF,
        ], true);
    }

    public function isPlainText(): bool
    {
        return $this->mimeType === self::MIME_PLAIN_TEXT;
    }

    /**
     * Dateiname fuer den Providerrequest. Bewusst neutral und ohne
     * Originaldateinamen, weil Dateinamen personenbezogene Angaben enthalten
     * koennen.
     */
    public function transportFileName(): string
    {
        $extension = match ($this->mimeType) {
            self::MIME_PDF => 'pdf',
            self::MIME_PNG => 'png',
            self::MIME_JPEG => 'jpg',
            self::MIME_WEBP => 'webp',
            self::MIME_GIF => 'gif',
            default => 'bin',
        };

        return 'dokument.'.$extension;
    }

    /**
     * Nur Metadaten. Kein Inhalt.
     *
     * @return array<string, scalar|null>
     */
    public function __debugInfo(): array
    {
        return [
            'neutralLabel' => $this->neutralLabel,
            'mimeType' => $this->mimeType,
            'byteSize' => $this->byteSize(),
            'pageCount' => $this->pageCount,
            'contents' => '[redigiert]',
            'preExtractedText' => $this->preExtractedText === null ? null : '[redigiert]',
        ];
    }

    /**
     * Serialisieren ist gesperrt, damit ein Dateiinhalt nicht ueber einen
     * Queue-Payload, Cache oder Session dauerhaft wird.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'DocumentPayload darf nicht serialisiert werden. Quelldateien gehoeren nicht in '
            .'Queue-Payloads, Caches oder Sessions.'
        );
    }
}
