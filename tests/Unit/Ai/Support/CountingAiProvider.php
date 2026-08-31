<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Enums\AiCallPurpose;
use App\Enums\AiCallStatus;
use App\Enums\DocumentType;
use App\Services\Ai\AiDocumentProviderInterface;
use App\Services\Ai\Dto\AiCallMetadata;
use App\Services\Ai\Dto\AiResultStatus;
use App\Services\Ai\Dto\AnalyzeContractRequest;
use App\Services\Ai\Dto\AnalyzePriorStatementRequest;
use App\Services\Ai\Dto\ClassificationResult;
use App\Services\Ai\Dto\ClassifyDocumentRequest;
use App\Services\Ai\Dto\DocumentPayload;
use App\Services\Ai\Dto\ExtractionResult;
use App\Services\Ai\Dto\ExtractStructuredDataRequest;
use App\Services\Ai\Dto\HealthCheckRequest;
use App\Services\Ai\Dto\HealthCheckResult;
use App\Services\Ai\Dto\ProviderFileDeletionOutcome;
use App\Services\Ai\Dto\ReconcileDocumentsRequest;
use App\Services\Ai\Dto\ReconciliationResult;
use Throwable;

/**
 * Provider fuer die Routingtests. Zaehlt Aufrufe und liefert ein steuerbares
 * Ergebnis.
 *
 * Es findet kein Netzwerkaufruf statt. Die Ergebnisse stammen aus den frei
 * erfundenen Fixtures in tests/Fixtures/Ai.
 */
final class CountingAiProvider implements AiDocumentProviderInterface
{
    public int $calls = 0;

    private ?Throwable $failure = null;

    private bool $schemaFailure = false;

    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    public function __construct(private readonly string $key) {}

    public function failWith(Throwable $exception): self
    {
        $this->failure = $exception;

        return $this;
    }

    public function returnSchemaFailure(): self
    {
        $this->schemaFailure = true;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function providerKey(): string
    {
        return $this->key;
    }

    public function supportsMimeType(string $mimeType): bool
    {
        return $mimeType === DocumentPayload::MIME_PDF;
    }

    public function classifyDocument(ClassifyDocumentRequest $request): ClassificationResult
    {
        $result = $this->run('dokumentklassifikation', AiCallPurpose::KLASSIFIKATION);
        $typeValue = $result->field('dokumenttyp')?->value;

        return new ClassificationResult(
            $result,
            is_string($typeValue) ? DocumentType::tryFrom($typeValue) : null,
            $result->field('dokumenttyp')->confidence ?? 0.0,
        );
    }

    public function extractStructuredData(ExtractStructuredDataRequest $request): ExtractionResult
    {
        return $this->run($request->schemaKey, AiCallPurpose::EXTRAKTION);
    }

    public function analyzeContract(AnalyzeContractRequest $request): ExtractionResult
    {
        return $this->run('mietvertrag', AiCallPurpose::VERTRAGSANALYSE);
    }

    public function analyzePriorStatement(AnalyzePriorStatementRequest $request): ExtractionResult
    {
        return $this->run('vorjahresabrechnung', AiCallPurpose::VORJAHRESANALYSE);
    }

    public function reconcileDocuments(ReconcileDocumentsRequest $request): ReconciliationResult
    {
        return new ReconciliationResult($this->run('reconciliation', AiCallPurpose::RECONCILIATION));
    }

    public function healthCheck(HealthCheckRequest $request): HealthCheckResult
    {
        return new HealthCheckResult(
            $this->key,
            'testmodell',
            true,
            true,
            false,
            200,
            1,
            $request->context->correlationId,
            'Testprovider erreichbar.',
            true,
        );
    }

    private function run(string $schemaKey, AiCallPurpose $purpose): ExtractionResult
    {
        $this->calls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $schema = AiTestFactory::schemas()->get($schemaKey);
        $prompt = AiTestFactory::prompts()->extraction($schema);

        $json = $this->payload !== null
            ? (string) json_encode($this->payload, JSON_THROW_ON_ERROR)
            : AiTestFactory::fixture($schemaKey.'.json');

        if ($this->schemaFailure) {
            $json = '{"kaputt": true}';
        }

        $outcome = AiTestFactory::validator()->validateJson($schema, $json);

        $metadata = new AiCallMetadata(
            $this->key,
            'testmodell',
            $purpose,
            $outcome->isValid() ? AiCallStatus::ERFOLGREICH : AiCallStatus::SCHEMA_FEHLER,
            $prompt->version,
            $prompt->hash(),
            $schema->key,
            $schema->version,
            $schema->hash(),
        );

        return new ExtractionResult(
            $outcome->isValid() ? AiResultStatus::VALIDIERT : AiResultStatus::FEHLGESCHLAGEN,
            $outcome->data(),
            AiTestFactory::confidence()->mark($outcome->fields()),
            $metadata,
            $outcome->violations(),
            [ProviderFileDeletionOutcome::notRequired($this->key)],
        );
    }
}
