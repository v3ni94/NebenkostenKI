<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use LogicException;
use SensitiveParameter;

/**
 * Die rohe, noch nicht validierte Modellantwort eines Providers.
 *
 * VERBINDLICHE DATENSCHUTZREGEL (Abschnitt 13.5): Dieses Objekt lebt
 * ausschliesslich im Arbeitsspeicher und wird unmittelbar nach der
 * Schemavalidierung verworfen. Der Nutzlastinhalt wird niemals geloggt, in
 * eine Ausnahme uebernommen, persistiert oder serialisiert.
 */
final class RawProviderResponse
{
    public function __construct(
        #[SensitiveParameter]
        private readonly string $jsonPayload,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?string $requestId = null,
        public readonly int $httpStatusCode = 200,
    ) {}

    public function jsonPayload(): string
    {
        return $this->jsonPayload;
    }

    public function payloadByteSize(): int
    {
        return strlen($this->jsonPayload);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function __debugInfo(): array
    {
        return [
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'requestId' => $this->requestId,
            'httpStatusCode' => $this->httpStatusCode,
            'payloadByteSize' => $this->payloadByteSize(),
            'jsonPayload' => '[redigiert]',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'RawProviderResponse darf nicht serialisiert werden. Rohe Modellantworten gehoeren nicht in '
            .'Queue-Payloads, Caches oder Logs.'
        );
    }
}
