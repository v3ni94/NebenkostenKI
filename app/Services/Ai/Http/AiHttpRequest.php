<?php

declare(strict_types=1);

namespace App\Services\Ai\Http;

use LogicException;
use SensitiveParameter;

/**
 * Eine ausgehende Provideranfrage.
 *
 * VERBINDLICHE DATENSCHUTZREGEL: Der Body enthaelt Dokumentinhalte. Er wird
 * niemals geloggt, in eine Ausnahme uebernommen oder serialisiert.
 * __debugInfo() liefert nur Metadaten, __serialize() ist gesperrt.
 * Autorisierungsheader werden in __debugInfo() ebenfalls redigiert.
 */
final class AiHttpRequest
{
    public const SECRET_HEADERS = ['authorization', 'x-api-key'];

    /**
     * @param  array<string, string>  $headers
     * @param  list<MultipartPart>  $multipart
     */
    private function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        #[SensitiveParameter]
        private readonly ?string $body,
        public readonly array $multipart,
        public readonly int $timeoutSeconds,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $json
     */
    public static function json(string $method, string $url, array $headers, array $json, int $timeoutSeconds): self
    {
        return new self(
            strtoupper($method),
            $url,
            $headers + ['content-type' => 'application/json'],
            json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            [],
            $timeoutSeconds,
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function get(string $url, array $headers, int $timeoutSeconds): self
    {
        return new self('GET', $url, $headers, null, [], $timeoutSeconds);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function delete(string $url, array $headers, int $timeoutSeconds): self
    {
        return new self('DELETE', $url, $headers, null, [], $timeoutSeconds);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<MultipartPart>  $parts
     */
    public static function multipart(string $url, array $headers, array $parts, int $timeoutSeconds): self
    {
        return new self('POST', $url, $headers, null, $parts, $timeoutSeconds);
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function isMultipart(): bool
    {
        return $this->multipart !== [];
    }

    public function bodyByteSize(): int
    {
        if ($this->body !== null) {
            return strlen($this->body);
        }

        $size = 0;

        foreach ($this->multipart as $part) {
            $size += $part->byteSize();
        }

        return $size;
    }

    /**
     * Nur Metadaten. Kein Body, keine Secrets.
     *
     * @return array<string, scalar|null>
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'headerNames' => implode(',', array_keys($this->headers)),
            'bodyByteSize' => $this->bodyByteSize(),
            'body' => '[redigiert]',
            'timeoutSeconds' => $this->timeoutSeconds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'AiHttpRequest darf nicht serialisiert werden. Anfragebodies gehoeren nicht in '
            .'Queue-Payloads, Caches oder Logs.'
        );
    }
}
