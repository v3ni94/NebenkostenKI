<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\ConfidenceEvaluator;
use App\Services\Ai\CostEstimator;
use App\Services\Ai\DailyCostLimiter;
use App\Services\Ai\Dto\DocumentPayload;
use App\Services\Ai\Dto\ProviderFileDeletionOutcome;
use App\Services\Ai\Exceptions\ProviderTransportException;
use App\Services\Ai\Http\AiHttpClientInterface;
use App\Services\Ai\Http\AiHttpRequest;
use App\Services\Ai\Http\MultipartPart;
use App\Services\Ai\JsonSchemaValidator;
use App\Services\Ai\Prompts\PromptRegistry;
use App\Services\Ai\ProviderConfig;
use App\Services\Ai\RedactingLogger;
use App\Services\Ai\Schemas\SchemaRegistry;

/**
 * Anthropic-Provider ueber die native Messages API (Abschnitt 13.4).
 *
 * VERBINDLICHE VORGABEN, DIE HIER UMGESETZT SIND:
 *
 * - Es wird ausschliesslich die Messages API verwendet, Endpunkt
 *   POST /v1/messages, mit PDF- und Vision-Unterstuetzung.
 * - Header nach offizieller Form: x-api-key mit dem API-Key,
 *   anthropic-version aus config('ai.providers.anthropic.version') und
 *   content-type application/json.
 * - PDF wird als Inhaltsblock document mit source.type base64 und
 *   media_type application/pdf uebergeben, Bilder als Inhaltsblock image.
 *   Die Datei wird nach Moeglichkeit direkt im Verarbeitungsrequest
 *   uebergeben.
 * - Ist die Files API technisch notwendig, also bei zu grosser Datei, wird die
 *   Datei ueber POST /v1/files angelegt, als source.type file referenziert und
 *   unmittelbar nach validierter Extraktion ueber DELETE /v1/files/{id}
 *   geloescht. Der Loeschstatus wird protokolliert.
 * - KEINE Message Batches, KEINE Code Execution und keine weitere Funktion mit
 *   laengerer Speicherung.
 * - Keine Automatisierung einer privaten Claude-Websession. Es wird
 *   ausschliesslich ein API-Key aus der Konfiguration verwendet.
 *
 * BEWUSST KONSERVATIV, STRUCTURED OUTPUTS: Die strikte Schemaausgabe wird
 * ueber den Systemprompt und die serverseitige Validierung mit hoechstens
 * zwei kontrollierten Reparaturversuchen erzwungen. Der providerseitige
 * Structured-Output-Modus ist ueber den Konstruktorschalter
 * useStructuredOutputs zuschaltbar und standardmaessig AUS. Grund: die
 * Unterstuetzung haengt am konkret konfigurierten Modell und die Feldform der
 * API ist versionsabhaengig. Vor dem Aktivieren ist gegen die aktuelle
 * offizielle API-Dokumentation und das konfigurierte Modell zu pruefen, ob
 * output_config.format in dieser Form akzeptiert wird und ob der zusaetzliche
 * Beta-Schalter noetig ist. Ein nicht akzeptiertes Feld fuehrt zu HTTP 400 und
 * damit zu einem vermeidbaren Ausfall.
 *
 * BEWUSST KONSERVATIV, LOESCHUNG: Das Loeschen eines Files-API-Objekts
 * garantiert keine sofortige physische Loeschung aller Providerkopien.
 * Verbindlich sind ZDR-Vereinbarung und aktuelle Retention-Dokumentation. Das
 * Loeschen ist notwendig, aber nicht ausreichend.
 */
final class AnthropicMessagesProvider extends AbstractHttpAiProvider
{
    public const MESSAGES_PATH = 'messages';

    public const FILES_PATH = 'files';

    public const MODELS_PATH = 'models';

    /**
     * Vorgabewert, falls in der Konfiguration keine API-Version steht.
     * Entspricht dem Wert in config/ai.php.
     */
    public const DEFAULT_API_VERSION = '2023-06-01';

    /**
     * Beta-Schalter fuer den providerseitigen Structured-Output-Modus. Wird
     * nur gesendet, wenn useStructuredOutputs aktiv ist. Vor dem Aktivieren
     * gegen die aktuelle offizielle Dokumentation pruefen.
     */
    public const STRUCTURED_OUTPUTS_BETA = 'structured-outputs-2025-11-13';

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

    public function __construct(
        ProviderConfig $providerConfig,
        AiHttpClientInterface $httpClient,
        SchemaRegistry $schemas,
        PromptRegistry $prompts,
        JsonSchemaValidator $validator,
        ConfidenceEvaluator $confidenceEvaluator,
        CostEstimator $costEstimator,
        DailyCostLimiter $costLimiter,
        RedactingLogger $logger,
        int $maxRetries = 2,
        int $inlineMaxBytes = self::DEFAULT_INLINE_MAX_BYTES,
        int $maxOutputTokens = 16000,
        private readonly bool $useStructuredOutputs = false,
    ) {
        parent::__construct(
            $providerConfig,
            $httpClient,
            $schemas,
            $prompts,
            $validator,
            $confidenceEvaluator,
            $costEstimator,
            $costLimiter,
            $logger,
            $maxRetries,
            $inlineMaxBytes,
            $maxOutputTokens,
        );
    }

    public function supportsMimeType(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), self::SUPPORTED_MIME_TYPES, true);
    }

    public function usesStructuredOutputs(): bool
    {
        return $this->useStructuredOutputs;
    }

    protected function sendSchemaRequest(
        SchemaCallPlan $plan,
        ?ProviderFileHandle $fileHandle,
        ?string $repairInstruction,
    ): RawProviderResponse {
        $body = [
            'model' => $plan->model,
            'max_tokens' => $this->maxOutputTokens,
            'system' => $plan->prompt->systemPrompt,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->buildUserContent($plan, $fileHandle, $repairInstruction),
                ],
            ],
        ];

        if ($this->useStructuredOutputs) {
            $body['output_config'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $plan->schema->providerSchemaName(),
                    'schema' => $plan->schema->jsonSchema(),
                ],
            ];
        }

        $response = $this->httpClient->send(AiHttpRequest::json(
            'POST',
            $this->providerConfig->endpoint(self::MESSAGES_PATH),
            $this->authHeaders($this->useStructuredOutputs),
            $body,
            $this->providerConfig->timeoutSeconds,
        ));

        $this->guardResponse($response);

        $decoded = $response->decoded();

        if ($decoded === null) {
            throw ProviderTransportException::malformedResponse($this->providerKey());
        }

        // Eine Ablehnung des Modells wird nicht als Nutzlast behandelt. Es
        // wird kein Antwortinhalt uebernommen.
        if (($decoded['stop_reason'] ?? null) === 'refusal') {
            throw ProviderTransportException::httpStatus($this->providerKey(), $response->statusCode, 'refusal');
        }

        $payload = self::extractText($decoded);

        if ($payload === null) {
            throw ProviderTransportException::malformedResponse($this->providerKey());
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $requestId = is_string($decoded['id'] ?? null) ? $decoded['id'] : $response->header('request-id');

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
            $this->authHeaders(false),
            [
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

        // Die Anthropic Files API kennt keine vom Aufrufer gesetzte
        // Kurzzeitfrist. Deshalb ist das aktive Loeschen unmittelbar nach der
        // validierten Extraktion der einzige Weg und verbindlich.
        return new ProviderFileHandle($fileId);
    }

    protected function deleteProviderFile(ProviderFileHandle $handle): ProviderFileDeletionOutcome
    {
        $response = $this->httpClient->send(AiHttpRequest::delete(
            $this->providerConfig->endpoint(self::FILES_PATH.'/'.$handle->fileId),
            $this->authHeaders(false),
            $this->providerConfig->timeoutSeconds,
        ));

        if (! $response->isSuccessful()) {
            return ProviderFileDeletionOutcome::failed(
                $this->providerKey(),
                $handle->fileId,
                $response->errorCode() ?? ('http_'.$response->statusCode),
            );
        }

        return ProviderFileDeletionOutcome::deleted($this->providerKey(), $handle->fileId);
    }

    protected function healthCheckRequest(string $model): AiHttpRequest
    {
        return AiHttpRequest::get(
            $this->providerConfig->endpoint(self::MODELS_PATH.'/'.$model),
            $this->authHeaders(false),
            $this->providerConfig->timeoutSeconds,
        );
    }

    protected function isUnsupportedFileErrorCode(string $errorCode): bool
    {
        $normalized = strtolower($errorCode);

        foreach (['unsupported', 'invalid_image', 'invalid_document', 'media_type'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Header nach offizieller Form der Messages API.
     *
     * @return array<string, string>
     */
    private function authHeaders(bool $withStructuredOutputsBeta): array
    {
        $headers = [
            'x-api-key' => (string) $this->providerConfig->apiKey(),
            'anthropic-version' => $this->providerConfig->apiVersion ?? self::DEFAULT_API_VERSION,
            'content-type' => 'application/json',
            'accept' => 'application/json',
        ];

        if ($withStructuredOutputsBeta) {
            $headers['anthropic-beta'] = self::STRUCTURED_OUTPUTS_BETA;
        }

        return $headers;
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
                $content[] = [
                    'type' => $document->isImage() ? 'image' : 'document',
                    'source' => ['type' => 'file', 'file_id' => $fileHandle->fileId],
                ];
            } elseif ($document->isImage()) {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $document->mimeType,
                        'data' => $document->base64(),
                    ],
                ];
            } elseif ($document->isPlainText()) {
                $content[] = [
                    'type' => 'text',
                    'text' => "Dokumentinhalt als untrusted data:\n".$document->contents(),
                ];
            } else {
                $content[] = [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => DocumentPayload::MIME_PDF,
                        'data' => $document->base64(),
                    ],
                ];
            }
        }

        $content[] = [
            'type' => 'text',
            'text' => $this->buildUserInstruction($plan, $repairInstruction),
        ];

        return $content;
    }

    /**
     * Liest den Antworttext aus der Messages-Antwort.
     *
     * Es werden ausschliesslich Bloecke vom Typ text uebernommen. Bloecke
     * anderer Art, insbesondere thinking, werden nicht als Nutzlast
     * behandelt.
     *
     * @param  array<string, mixed>  $decoded
     */
    private static function extractText(array $decoded): ?string
    {
        $blocks = $decoded['content'] ?? null;

        if (! is_array($blocks)) {
            return null;
        }

        $parts = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode('', $parts);
    }
}
