<?php

declare(strict_types=1);

namespace App\Services\Ai\Http;

use App\Services\Ai\Exceptions\ProviderTransportException;

/**
 * Schmaler Transportadapter der KI-Schicht.
 *
 * Begruendung fuer die eigene Schnittstelle statt einer direkten Bindung an
 * die Http-Facade von Laravel: die Providerklassen bleiben dadurch ohne
 * Framework-Bootstrap testbar (ADR-001). Die produktive Implementierung
 * PsrAiHttpClient nutzt den PSR-18-konformen Guzzle-Client aus
 * composer.json (ADR-008).
 *
 * VERBINDLICH: Eine Implementierung darf Anfrage- und Antwortbodies nicht
 * loggen, nicht in Ausnahmen uebernehmen und nicht zwischenspeichern.
 */
interface AiHttpClientInterface
{
    /**
     * @throws ProviderTransportException bei Netzwerkfehler oder Timeout
     */
    public function send(AiHttpRequest $request): AiHttpResponse;
}
