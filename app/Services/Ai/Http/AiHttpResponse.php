<?php

declare(strict_types=1);

namespace App\Services\Ai\Http;

use LogicException;
use SensitiveParameter;

/**
 * Eine Providerantwort.
 *
 * VERBINDLICHE DATENSCHUTZREGEL: Der Body enthaelt die rohe Modellantwort und
 * damit Dokumentinhalt. Er lebt ausschliesslich im Arbeitsspeicher, wird nach
 * der Schemavalidierung verworfen und niemals geloggt, in eine Ausnahme
 * uebernommen oder serialisiert.
 */
final class AiHttpResponse
{
    /**
     * @param  array<string, string>  $headers  Kleingeschriebene Headernamen.
     */
    public function __construct(
        public readonly int $statusCode,
        #[SensitiveParameter]
        private readonly string $body,
        public readonly array $headers = [],
    ) {}

    public function body(): string
    {
        return $this->body;
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isRateLimited(): bool
    {
        return $this->statusCode === 429;
    }

    /**
     * 529 verwendet Anthropic fuer eine zeitweilige Ueberlastung. Der Fall ist
     * technisch, nicht fachlich, und daher wie ein Serverfehler zu behandeln.
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function retryAfterSeconds(): ?int
    {
        $value = $this->header('retry-after');

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Dekodiert den Body als Array oder liefert null.
     *
     * @return array<string, mixed>|null
     */
    public function decoded(): ?array
    {
        $decoded = json_decode($this->body, true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Fehlercode der Providerantwort, soweit vorhanden.
     *
     * Es wird ausschliesslich der maschinenlesbare Code uebernommen, niemals
     * die Fehlermeldung, weil diese Teile der Anfrage wiedergeben kann.
     */
    public function errorCode(): ?string
    {
        $decoded = $this->decoded();

        if ($decoded === null) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        if (! is_array($error)) {
            return null;
        }

        foreach (['code', 'type'] as $key) {
            $value = $error[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 80);
            }
        }

        return null;
    }

    public function bodyByteSize(): int
    {
        return strlen($this->body);
    }

    /**
     * Nur Metadaten. Kein Body.
     *
     * @return array<string, scalar|null>
     */
    public function __debugInfo(): array
    {
        return [
            'statusCode' => $this->statusCode,
            'bodyByteSize' => $this->bodyByteSize(),
            'body' => '[redigiert]',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'AiHttpResponse darf nicht serialisiert werden. Rohe Modellantworten gehoeren nicht in '
            .'Queue-Payloads, Caches oder Logs.'
        );
    }
}
