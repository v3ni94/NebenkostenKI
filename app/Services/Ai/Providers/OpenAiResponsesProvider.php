<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Dto\DocumentPayload;
use App\Services\Ai\Dto\ProviderFileDeletionOutcome;
use App\Services\Ai\Exceptions\ProviderTransportException;
use App\Services\Ai\Http\AiHttpRequest;
use App\Services\Ai\Http\MultipartPart;

/**
 * OpenAI-Provider ueber die Responses API (Abschnitt 13.3).
 *
 * VERBINDLICHE VORGABEN, DIE HIER UMGESETZT SIND:
 *
 * - Es wird ausschliesslich die Responses API verwendet, Endpunkt
 *   POST /v1/responses.
 * - Bei JEDER Anfrage wird store ausdruecklich auf false gesetzt. Das
 *   reduziert die API-seitige Persistenz, ist aber KEINE Zero Data Retention
 *   und ersetzt nicht die ZDR-Freigabe des Projekts. Die Freigabe prueft
 *   ProviderReleaseGate.
 * - Structured Outputs mit strengem JSON Schema, also
 *   text.format.type = json_schema mit strict = true.
 * - Die Datei wird nach Moeglichkeit DIREKT im Verarbeitungsrequest
 *   uebergeben, als input_file mit file_data als Data-URI beziehungsweise als
 *   input_image. Kein Vector Store, kein Wissensspeicher.
 * - Ist die Files API technisch notwendig, also bei zu grosser Datei, wird die
 *   kuerzestmoegliche unterstuetzte expires_after-Frist gesetzt und die Datei
 *   unmittelbar nach validierter Extraktion zusaetzlich ueber
 *   DELETE /v1/files/{id} geloescht. Der Loeschstatus wird protokolliert.
 * - KEINE Background-, Batch-, Vector-Store-, File-Search- oder
 *   Code-Interpreter-Funktion. Es wird kein tools-Feld gesendet.
 * - Keine Automatisierung einer ChatGPT-Websession. Es wird ausschliesslich
 *   ein API-Key aus der Konfiguration verwendet.
 *
 * BEWUSST KONSERVATIV: Als kuerzeste Aufbewahrungsfrist der Files API wird
 * eine Stunde gesetzt. Kuerzere Werte werden von der API abgelehnt. Die Frist
 * ersetzt nicht das aktive Loeschen; beides wird kombiniert.
 */
final class OpenAiResponsesProvider extends AbstractHttpAiProvider
{
    public const RESPONSES_PATH = 'responses';

    public const FILES_PATH = 'files';

    public const MODELS_PATH = 'models';

    /**
     * Kuerzeste von der Files API unterstuetzte Aufbewahrungsfrist in
     * Sekunden. Bewusst konservativ, weil kuerzere Werte abgelehnt werden.
     */
    public const SHORTEST_FILE_EXPIRY_SECONDS = 3600;

    /**
     * Zweck der Dateiupload-Anfrage. user_data ist der Zweck fuer
     * Dateieingaben in Modellanfragen. Es wird ausdruecklich NICHT
     * assistants, fine-tune, batch oder vision verwendet, weil damit weitere
     * Speicherfunktionen verbunden sind.
     */
    public const FILE_PURPOSE = 'user_data';

    /**
     * @var list<string>
     */
    private const SUPPORTED_MIME_TYPES = [
        DocumentPayload::MIME_PDF,
        DocumentPayload::MIME_PNG,
        DocumentPayload::MIME_JPEG,
        DocumentPayload::MIME_WEBP,
        DocumentPayload::MIME_GIF,
        DocumentPayload::MIME_PLAIN_TEXT,
    ];

    public function supportsMimeType(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), self::SUPPORTED_MIME_TYPES, true);
    }

    protected function sendSchemaRequest(
        SchemaCallPlan $plan,
        ?ProviderFileHandle $fileHandle,
        ?string $repairInstruction,
    ): RawProviderResponse {
        $body = [
            'model' => $plan->model,
            // Verbindlich bei jeder Anfrage. Keine Zero Data Retention, aber
            // die geringste API-seitige Persistenz.
            'store' => false,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => $plan->prompt->systemPrompt],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildUserContent($plan, $fileHandle, $repairInstruction),
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $plan->schema->providerSchemaName(),
                    'strict' => true,
                    'schema' => $plan->schema->jsonSchema(),
                ],
            ],
            'max_output_tokens' => $this->maxOutputTokens,
        ];

        $response = $this->httpClient->send(AiHttpRequest::json(
            'POST',
            $this->providerConfig->endpoint(self::RESPONSES_PATH),
            $this->authHeaders(),
            $body,
            $this->providerConfig->timeoutSeconds,
        ));

        $this->guardResponse($response);

        $decoded = $response->decoded();

        if ($decoded === null) {
            throw ProviderTransportException::malformedResponse($this->providerKey());
        }

        $payload = self::extractOutputText($decoded);

        if ($payload === null) {
            throw ProviderTransportException::malformedResponse($this->providerKey());
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $requestId = is_string($decoded['id'] ?? null) ? $decoded['id'] : $response->header('x-request-id');

        return new RawProviderResponse(
            $payload,
            is_numeric($usage['input_tokens'] ?? null) ? (int) $usage['input_tokens'] : 0,
            is_numeric($usage['output_tokens'] ?? null) ? (int) $usage['output_tokens'] : 0,
            $requestId,
            $response->statusCode,
        );
    }

    protected function uploadProviderFile(DocumentPayload $document): ProviderFileHandle
    {
        $response = $this->httpClient->send(AiHttpRequest::multipart(
            $this->providerConfig->endpoint(self::FILES_PATH),
            $this->authHeaders(),
            [
                MultipartPart::field('purpose', self::FILE_PURPOSE),
                MultipartPart::field('expires_after[anchor]', 'created_at'),
                MultipartPart::field('expires_after[seconds]', (string) self::SHORTEST_FILE_EXPIRY_SECONDS),
                MultipartPart::file(
                    'file',
                    $document->transportFileName(),
                    $document->mimeType,
                    $document->contents(),
                ),
            ],
            $this->providerConfig->timeoutSeconds,
        ));

        $this->guardResponse($response);

        $decoded = $response->decoded();
        $fileId = is_string($decoded['id'] ?? null) ? $decoded['id'] : null;

        if ($fileId === null) {
            throw ProviderTransportException::malformedResponse($this->providerKey());
        }

        return new ProviderFileHandle($fileId, self::SHORTEST_FILE_EXPIRY_SECONDS);
    }

    protected function deleteProviderFile(ProviderFileHandle $handle): ProviderFileDeletionOutcome
    {
        $response = $this->httpClient->send(AiHttpRequest::delete(
            $this->providerConfig->endpoint(self::FILES_PATH.'/'.$handle->fileId),
            $this->authHeaders(),
            $this->providerConfig->timeoutSeconds,
        ));

        if (! $response->isSuccessful()) {
            return ProviderFileDeletionOutcome::failed(
                $this->providerKey(),
                $handle->fileId,
                $response->errorCode() ?? ('http_'.$response->statusCode),
            );
        }

        $decoded = $response->decoded();

        if (($decoded['deleted'] ?? null) !== true) {
            return ProviderFileDeletionOutcome::failed(
                $this->providerKey(),
                $handle->fileId,
                'loeschung_nicht_bestaetigt',
            );
        }

        return ProviderFileDeletionOutcome::deleted($this->providerKey(), $handle->fileId);
    }

    protected function healthCheckRequest(string $model): AiHttpRequest
    {
        return AiHttpRequest::get(
            $this->providerConfig->endpoint(self::MODELS_PATH.'/'.$model),
            $this->authHeaders(),
            $this->providerConfig->timeoutSeconds,
        );
    }

    protected function isUnsupportedFileErrorCode(string $errorCode): bool
    {
        $normalized = strtolower($errorCode);

        foreach (['unsupported_file', 'unsupported_mimetype', 'invalid_image', 'invalid_file_format', 'unsupported_media_type'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return [
            'authorization' => 'Bearer '.((string) $this->providerConfig->apiKey()),
            'accept' => 'application/json',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildUserContent(
        SchemaCallPlan $plan,
        ?ProviderFileHandle $fileHandle,
        ?string $repairInstruction,
    ): array {
        $content = [];
        $document = $plan->document;

        if ($document !== null) {
            if ($fileHandle !== null) {
                // Nur wenn die Direktuebergabe technisch nicht moeglich war.
                $content[] = ['type' => 'input_file', 'file_id' => $fileHandle->fileId];
            } elseif ($document->isImage()) {
                $content[] = [
                    'type' => 'input_image',
                    'image_url' => sprintf('data:%s;base64,%s', $document->mimeType, $document->base64()),
                ];
            } elseif ($document->isPlainText()) {
                $content[] = [
                    'type' => 'input_text',
                    'text' => "Dokumentinhalt als untrusted data:\n".$document->contents(),
                ];
            } else {
                $content[] = [
                    'type' => 'input_file',
                    'filename' => $document->transportFileName(),
                    'file_data' => sprintf('data:%s;base64,%s', $document->mimeType, $document->base64()),
                ];
            }
        }

        $content[] = [
            'type' => 'input_text',
            'text' => $this->buildUserInstruction($plan, $repairInstruction),
        ];

        return $content;
    }

    /**
     * Liest den Ausgabetext aus der Responses-Antwort.
     *
     * Die Antwort enthaelt output als Liste von Nachrichten mit
     * Inhaltsbloecken. Es werden ausschliesslich Bloecke vom Typ output_text
     * uebernommen. Ein refusal-Block wird nicht als Nutzlast behandelt,
     * sondern fuehrt zu null und damit zu einem technischen Fehler ohne
     * Inhaltsuebernahme.
     *
     * @param  array<string, mixed>  $decoded
     */
    private static function extractOutputText(array $decoded): ?string
    {
        $output = $decoded['output'] ?? null;

        if (! is_array($output)) {
            return null;
        }

        $parts = [];

        foreach ($output as $item) {
            if (! is_array($item) || ! is_array($item['content'] ?? null)) {
                continue;
            }

            foreach ($item['content'] as $block) {
                if (! is_array($block)) {
                    continue;
                }

                if (($block['type'] ?? null) === 'output_text' && is_string($block['text'] ?? null)) {
                    $parts[] = $block['text'];
                }
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode('', $parts);
    }
}
