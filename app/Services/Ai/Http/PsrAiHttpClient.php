<?php

declare(strict_types=1);

namespace App\Services\Ai\Http;

use App\Services\Ai\Exceptions\ProviderTransportException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Produktiver Transportadapter ueber einen injizierten PSR-18-Client.
 *
 * Guzzle 7 erfuellt PSR-18 und PSR-17 und ist bereits Projektabhaengigkeit
 * (ADR-008). Der Client wird injiziert, damit die Providerklassen im Test
 * ohne Netzwerk und ohne Framework-Bootstrap laufen.
 *
 * VERBINDLICHE DATENSCHUTZREGEL: Diese Klasse loggt nichts. Sie uebernimmt
 * bei einem Netzwerkfehler ausschliesslich den Ausnahmetyp in die
 * Ursachenkette, nicht den Anfrage- oder Antwortbody. Ein Timeout wird als
 * technischer Fehler gemeldet, damit der Router einen zulaessigen Fallback
 * pruefen kann.
 */
final class PsrAiHttpClient implements AiHttpClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $providerKeyForErrors = 'unbekannt',
    ) {}

    public function send(AiHttpRequest $request): AiHttpResponse
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);

        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        if ($request->isMultipart()) {
            $boundary = 'sa'.bin2hex(random_bytes(16));
            $psrRequest = $psrRequest
                ->withHeader('content-type', 'multipart/form-data; boundary='.$boundary)
                ->withBody($this->streamFactory->createStream($this->encodeMultipart($request, $boundary)));
        } elseif ($request->body() !== null) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body()));
        }

        try {
            $psrResponse = $this->client->sendRequest($psrRequest);
        } catch (Throwable $exception) {
            // Bewusst ohne Body und ohne Providermeldung.
            throw ProviderTransportException::network($this->providerKeyForErrors, $exception);
        }

        $headers = [];

        foreach ($psrResponse->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return new AiHttpResponse(
            $psrResponse->getStatusCode(),
            (string) $psrResponse->getBody(),
            $headers,
        );
    }

    private function encodeMultipart(AiHttpRequest $request, string $boundary): string
    {
        $segments = [];

        foreach ($request->multipart as $part) {
            $headers = sprintf('Content-Disposition: form-data; name="%s"', $part->name);

            if ($part->fileName !== null) {
                $headers .= sprintf('; filename="%s"', $part->fileName);
            }

            $headers .= "\r\n";

            if ($part->contentType !== null) {
                $headers .= sprintf("Content-Type: %s\r\n", $part->contentType);
            }

            $segments[] = "--{$boundary}\r\n{$headers}\r\n{$part->contents()}\r\n";
        }

        return implode('', $segments)."--{$boundary}--\r\n";
    }
}
