<?php

declare(strict_types=1);

namespace App\Services\Ai\Http;

use SensitiveParameter;

/**
 * Ein Teil einer multipart/form-data-Anfrage.
 *
 * Wird nur fuer die Files-APIs der Provider verwendet, also nur, wenn die
 * Datei nicht direkt in den Verarbeitungsrequest passt.
 */
final class MultipartPart
{
    private function __construct(
        public readonly string $name,
        #[SensitiveParameter]
        private readonly string $contents,
        public readonly ?string $fileName = null,
        public readonly ?string $contentType = null,
    ) {}

    public static function field(string $name, string $value): self
    {
        return new self($name, $value);
    }

    public static function file(string $name, string $fileName, string $contentType, #[SensitiveParameter] string $contents): self
    {
        return new self($name, $contents, $fileName, $contentType);
    }

    public function contents(): string
    {
        return $this->contents;
    }

    public function byteSize(): int
    {
        return strlen($this->contents);
    }

    public function isFile(): bool
    {
        return $this->fileName !== null;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'fileName' => $this->fileName,
            'contentType' => $this->contentType,
            'byteSize' => $this->byteSize(),
            'contents' => '[redigiert]',
        ];
    }
}
