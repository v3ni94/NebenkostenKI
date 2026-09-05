<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Exceptions\ProviderTransportException;
use App\Services\Ai\Http\AiHttpClientInterface;
use App\Services\Ai\Http\AiHttpRequest;
use App\Services\Ai\Http\AiHttpResponse;
use RuntimeException;

/**
 * Transportadapter fuer Tests. Es findet KEIN Netzwerkaufruf statt und damit
 * kein kostenpflichtiger Providerzugriff.
 *
 * Die Antworten werden vorab als Skript hinterlegt. Alle Anfragen werden
 * mitgeschrieben, damit die Contracttests Endpunkt, Header und Bodyfelder
 * pruefen koennen.
 */
final class RecordingAiHttpClient implements AiHttpClientInterface
{
    /** @var list<AiHttpRequest> */
    public array $requests = [];

    /** @var list<AiHttpResponse|ProviderTransportException> */
    private array $script = [];

    private int $index = 0;

    private ?AiHttpResponse $default = null;

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, string>  $headers
     */
    public function pushJson(array $json, int $statusCode = 200, array $headers = []): self
    {
        $this->script[] = new AiHttpResponse(
            $statusCode,
            (string) json_encode($json, JSON_THROW_ON_ERROR),
            $headers,
        );

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function pushRaw(string $body, int $statusCode = 200, array $headers = []): self
    {
        $this->script[] = new AiHttpResponse($statusCode, $body, $headers);

        return $this;
    }

    public function pushTransportError(string $providerKey = 'test'): self
    {
        $this->script[] = ProviderTransportException::network($providerKey);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    public function setDefaultJson(array $json, int $statusCode = 200): self
    {
        $this->default = new AiHttpResponse(
            $statusCode,
            (string) json_encode($json, JSON_THROW_ON_ERROR),
        );

        return $this;
    }

    public function send(AiHttpRequest $request): AiHttpResponse
    {
        $this->requests[] = $request;

        $next = $this->script[$this->index] ?? null;

        if ($next === null) {
            if ($this->default !== null) {
                return $this->default;
            }

            throw new RuntimeException(sprintf(
                'Es sind keine weiteren Testantworten hinterlegt (Anfrage %d an %s).',
                $this->index + 1,
                $request->url,
            ));
        }

        $this->index++;

        if ($next instanceof ProviderTransportException) {
            throw $next;
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): AiHttpRequest
    {
        $last = $this->requests[array_key_last($this->requests)] ?? null;

        if ($last === null) {
            throw new RuntimeException('Es wurde keine Anfrage aufgezeichnet.');
        }

        return $last;
    }

    public function requestAt(int $index): AiHttpRequest
    {
        $request = $this->requests[$index] ?? null;

        if ($request === null) {
            throw new RuntimeException(sprintf('Es gibt keine Anfrage mit Index %d.', $index));
        }

        return $request;
    }

    /**
     * Dekodierter Body einer aufgezeichneten Anfrage.
     *
     * @return array<string, mixed>
     */
    public function decodedBodyAt(int $index): array
    {
        $body = $this->requestAt($index)->body();

        if ($body === null) {
            throw new RuntimeException(sprintf('Anfrage %d hat keinen JSON-Body.', $index));
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Body der Anfrage %d ist kein JSON-Objekt.', $index));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_map(
            static fn (AiHttpRequest $request): string => $request->method.' '.$request->url,
            $this->requests,
        );
    }
}
