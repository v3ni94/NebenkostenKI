<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Enums\AiCallPurpose;
use App\Enums\AiCallStatus;
use App\Enums\DocumentType;
use App\Services\Ai\AiDocumentProviderInterface;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\ConfidenceEvaluator;
use App\Services\Ai\CostEstimator;
use App\Services\Ai\Dto\AiCallMetadata;
use App\Services\Ai\Dto\AiRequestContext;
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
use App\Services\Ai\Exceptions\UnsupportedFileTypeException;
use App\Services\Ai\JsonSchemaValidator;
use App\Services\Ai\Prompts\PromptRegistry;
use App\Services\Ai\RedactingLogger;
use App\Services\Ai\Schemas\SchemaRegistry;
use RuntimeException;

/**
 * Testprovider ohne Netzwerkaufruf.
 *
 * Zweck: lokale Entwicklung und Tests ohne kostenpflichtige Providerzugriffe.
 * config/ai.php erlaubt dafuer AI_PRIMARY_PROVIDER=fake, phpunit.xml setzt
 * diesen Wert im Standardtestlauf.
 *
 * Der Provider liest frei erfundene, anonymisierte Beispielantworten aus
 * tests/Fixtures/Ai und schickt sie durch dieselbe Pipeline wie ein echter
 * Provider, also durch JsonSchemaValidator und ConfidenceEvaluator. Damit
 * pruefen die Contracttests genau den Vertrag, den auch die HTTP-Provider
 * einhalten muessen.
 *
 * VERBINDLICH: Der Provider ist produktiv gesperrt. ProviderReleaseGate laesst
 * ihn nur in den Umgebungen local und testing zu, weil er keine fachlich
 * belastbaren Ergebnisse liefert.
 *
 * Es findet kein Netzwerkaufruf statt. Es wird keine Providerdatei angelegt,
 * deshalb ist der Loeschstatus immer NICHT_ERFORDERLICH.
 */
final class FakeAiProvider implements AiDocumentProviderInterface
{
    public const MODEL = 'fake-testprovider';

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

    /**
     * @param  array<string, string>  $fixtureOverrides  Schemaschluessel auf Dateinamen.
     */
    public function __construct(
        private readonly string $fixtureDirectory,
        private readonly SchemaRegistry $schemas,
        private readonly PromptRegistry $prompts,
        private readonly JsonSchemaValidator $validator,
        private readonly ConfidenceEvaluator $confidenceEvaluator,
        private readonly CostEstimator $costEstimator,
        private readonly RedactingLogger $logger,
        private array $fixtureOverrides = [],
    ) {}

    public function providerKey(): string
    {
        return AiProviderKey::FAKE->value;
    }

    /**
     * Legt fuer einen Schemaschluessel eine andere Fixture fest, zum Beispiel
     * eine absichtlich schemaverletzende oder eine Fixture mit eingebettetem
     * Anweisungstext.
     */
    public function withFixture(string $schemaKey, string $fileName): self
    {
        $clone = clone $this;
        $clone->fixtureOverrides[$schemaKey] = $fileName;

        return $clone;
    }

    public function supportsMimeType(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), self::SUPPORTED_MIME_TYPES, true);
    }

    public function classifyDocument(ClassifyDocumentRequest $request): ClassificationResult
    {
        $this->assertMimeTypeSupported($request->document);

        $result = $this->execute(
            AiCallPurpose::KLASSIFIKATION,
            'dokumentklassifikation',
            $request->context,
        );

        $typeField = $result->field('dokumenttyp');
        $rawType = $typeField?->value;
        $instructionField = $result->field('enthaelt_anweisungstext');

        return new ClassificationResult(
            $result,
            is_string($rawType) ? DocumentType::tryFrom($rawType) : null,
            $typeField?->confidence ?? 0.0,
            [],
            $instructionField?->value === true,
        );
    }

    public function extractStructuredData(ExtractStructuredDataRequest $request): ExtractionResult
    {
        $this->assertMimeTypeSupported($request->document);

        return $this->execute(AiCallPurpose::EXTRAKTION, $request->schemaKey, $request->context);
    }

    public function analyzeContract(AnalyzeContractRequest $request): ExtractionResult
    {
        $this->assertMimeTypeSupported($request->document);

        return $this->execute(AiCallPurpose::VERTRAGSANALYSE, 'mietvertrag', $request->context);
    }

    public function analyzePriorStatement(AnalyzePriorStatementRequest $request): ExtractionResult
    {
        $this->assertMimeTypeSupported($request->document);

        return $this->execute(AiCallPurpose::VORJAHRESANALYSE, 'vorjahresabrechnung', $request->context);
    }

    public function reconcileDocuments(ReconcileDocumentsRequest $request): ReconciliationResult
    {
        return new ReconciliationResult(
            $this->execute(AiCallPurpose::RECONCILIATION, 'reconciliation', $request->context),
        );
    }

    public function healthCheck(HealthCheckRequest $request): HealthCheckResult
    {
        return new HealthCheckResult(
            $this->providerKey(),
            self::MODEL,
            true,
            true,
            false,
            200,
            0,
            $request->context->correlationId,
            'Testprovider ohne Netzwerkaufruf. Ausschliesslich fuer lokale Entwicklung und Tests.',
            false,
        );
    }

    /**
     * Pfad der verwendeten Fixture, fuer Tests und Fehlermeldungen.
     */
    public function fixturePath(string $schemaKey): string
    {
        $fileName = $this->fixtureOverrides[$schemaKey] ?? $schemaKey.'.json';

        return rtrim($this->fixtureDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$fileName;
    }

    private function execute(AiCallPurpose $purpose, string $schemaKey, AiRequestContext $context): ExtractionResult
    {
        $start = hrtime(true);
        $schema = $this->schemas->get($schemaKey);
        $prompt = $this->promptFor($purpose, $schemaKey);

        $outcome = $this->validator->validateJson($schema, $this->readFixture($schemaKey));
        $validated = $outcome->isValid();

        $estimate = $this->costEstimator->estimate(self::MODEL, 0, 0);

        $metadata = new AiCallMetadata(
            $this->providerKey(),
            self::MODEL,
            $purpose,
            $validated ? AiCallStatus::ERFOLGREICH : AiCallStatus::SCHEMA_FEHLER,
            $prompt->version,
            $prompt->hash(),
            $schema->key,
            $schema->version,
            $schema->hash(),
            0,
            0,
            $estimate->costCent(),
            $estimate->costMilliCentOrNull(),
            $estimate->basisAvailable,
            (int) round((hrtime(true) - $start) / 1_000_000),
            null,
            [],
            $context->correlationId,
            1,
            null,
        );

        $result = new ExtractionResult(
            $validated ? AiResultStatus::VALIDIERT : AiResultStatus::FEHLGESCHLAGEN,
            $outcome->data(),
            $this->confidenceEvaluator->mark($outcome->fields()),
            $metadata,
            $outcome->violations(),
            [ProviderFileDeletionOutcome::notRequired($this->providerKey())],
        );

        $this->logger->info('Testprovider hat eine Beispielantwort geliefert', $metadata->toLogContext());

        return $result;
    }

    private function promptFor(AiCallPurpose $purpose, string $schemaKey): \App\Services\Ai\Prompts\PromptDefinition
    {
        $schema = $this->schemas->get($schemaKey);

        return match ($purpose) {
            AiCallPurpose::KLASSIFIKATION => $this->prompts->classification($schema),
            AiCallPurpose::VERTRAGSANALYSE => $this->prompts->contractAnalysis($schema),
            AiCallPurpose::VORJAHRESANALYSE => $this->prompts->priorStatementAnalysis($schema),
            AiCallPurpose::RECONCILIATION => $this->prompts->reconciliation($schema),
            AiCallPurpose::EXTRAKTION, AiCallPurpose::HEALTHCHECK => $this->prompts->extraction($schema),
        };
    }

    private function readFixture(string $schemaKey): string
    {
        $path = $this->fixturePath($schemaKey);

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(sprintf(
                'Fixture fuer Schema "%s" fehlt oder ist nicht lesbar: %s',
                $schemaKey,
                $path,
            ));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Fixture konnte nicht gelesen werden: %s', $path));
        }

        return $contents;
    }

    private function assertMimeTypeSupported(DocumentPayload $document): void
    {
        if ($this->supportsMimeType($document->mimeType)) {
            return;
        }

        throw UnsupportedFileTypeException::forMimeType($this->providerKey(), $document->mimeType);
    }
}
