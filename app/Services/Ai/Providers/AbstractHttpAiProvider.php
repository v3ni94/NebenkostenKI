<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Enums\AiCallPurpose;
use App\Enums\AiCallStatus;
use App\Enums\DocumentType;
use App\Services\Ai\AiDocumentProviderInterface;
use App\Services\Ai\ConfidenceEvaluator;
use App\Services\Ai\CostEstimator;
use App\Services\Ai\DailyCostLimiter;
use App\Services\Ai\Dto\AiCallMetadata;
use App\Services\Ai\Dto\AiResultStatus;
use App\Services\Ai\Dto\AnalyzeContractRequest;
use App\Services\Ai\Dto\AnalyzePriorStatementRequest;
use App\Services\Ai\Dto\ClassificationResult;
use App\Services\Ai\Dto\ClassifyDocumentRequest;
use App\Services\Ai\Dto\DocumentPayload;
use App\Services\Ai\Dto\ExtractedValue;
use App\Services\Ai\Dto\ExtractionResult;
use App\Services\Ai\Dto\ExtractStructuredDataRequest;
use App\Services\Ai\Dto\HealthCheckRequest;
use App\Services\Ai\Dto\HealthCheckResult;
use App\Services\Ai\Dto\ProviderFileDeletionOutcome;
use App\Services\Ai\Dto\ReconcileDocumentsRequest;
use App\Services\Ai\Dto\ReconciliationResult;
use App\Services\Ai\Dto\ReconciliationSubject;
use App\Services\Ai\Dto\SchemaViolation;
use App\Services\Ai\Exceptions\CostBasisMissingException;
use App\Services\Ai\Exceptions\ProviderFileNotReleasedException;
use App\Services\Ai\Exceptions\ProviderTransportException;
use App\Services\Ai\Exceptions\RateLimitException;
use App\Services\Ai\Exceptions\UnsupportedFileTypeException;
use App\Services\Ai\Http\AiHttpClientInterface;
use App\Services\Ai\Http\AiHttpRequest;
use App\Services\Ai\Http\AiHttpResponse;
use App\Services\Ai\JsonSchemaValidator;
use App\Services\Ai\Prompts\PromptRegistry;
use App\Services\Ai\ProviderConfig;
use App\Services\Ai\RedactingLogger;
use App\Services\Ai\Schemas\SchemaRegistry;
use Throwable;

/**
 * Gemeinsame Ablauflogik beider HTTP-Provider.
 *
 * Hier liegen die Teile, die providerunabhaengig sind und genau einmal
 * existieren duerfen:
 *
 * - lokale Pruefung des MIME-Typs vor dem Versand, damit keine unnoetigen
 *   Dokumentinhalte uebertragen werden
 * - Vorabpruefung des Tagesbudgets
 * - Entscheidung, ob die Datei direkt im Verarbeitungsrequest uebergeben wird
 *   oder ueber die Files-API des Providers laufen muss
 * - Schleife mit hoechstens maxRetries kontrollierten Reparaturversuchen
 * - serverseitige Schemavalidierung und sofortiges Verwerfen der rohen
 *   Modellantwort
 * - Kennzeichnung pruefpflichtiger Felder nach Konfidenz
 * - Kostenschaetzung und Metadaten
 * - Loeschung der temporaeren Providerdatei im finally-Zweig, also auch bei
 *   Fehler und Abbruch, mit protokollierbarem Loeschstatus
 *
 * VERBINDLICHE DATENSCHUTZREGELN:
 *
 * 1. Die rohe Modellantwort wird nach der Validierung sofort verworfen und
 *    verlaesst diese Klasse nicht.
 * 2. Es werden ausschliesslich Metadaten protokolliert, gefiltert ueber
 *    RedactingLogger.
 * 3. Eine Schemaverletzung fuehrt zu Status FEHLGESCHLAGEN als Rueckgabewert,
 *    nicht zu einer unbehandelten Ausnahme.
 * 4. Der Reparaturprompt enthaelt ausschliesslich Schemapfade und
 *    Verletzungscodes. Die vorherige Modellantwort wird bewusst NICHT
 *    zurueckgespielt, weil sie untrusted Dokumentinhalt ist und ein
 *    Zurueckspielen die Wirkung eines eingebetteten Anweisungstextes
 *    verstaerken wuerde.
 */
abstract class AbstractHttpAiProvider implements AiDocumentProviderInterface
{
    /**
     * Grenze, bis zu der eine Datei direkt im Verarbeitungsrequest uebergeben
     * wird. Darueber ist der Umweg ueber die Files-API des Providers
     * technisch notwendig.
     *
     * Konservativ gewaehlt, weil Base64 den Umfang um etwa ein Drittel
     * erhoeht und die Provider die Gesamtgroesse einer Anfrage begrenzen. Der
     * Wert ist konstruktorseitig anpassbar, ohne config/ai.php zu aendern.
     */
    public const DEFAULT_INLINE_MAX_BYTES = 4 * 1024 * 1024;

    /**
     * Schemata, fuer die nach Abschnitt 13.8 das leistungsfaehigere
     * Analysemodell verwendet wird: Vertraege, komplexe Tabellen und
     * Reconciliation.
     *
     * @var list<string>
     */
    protected const ANALYZE_MODEL_SCHEMAS = [
        'mietvertrag',
        'hausgeldabrechnung',
        'heizkostenabrechnung',
        'vorjahresabrechnung',
        'mieter_einheitenliste',
        'reconciliation',
    ];

    /**
     * Grobe Schaetzung der Eingabetoken je Dokumentseite, ausschliesslich
     * fuer die Vorabpruefung des Tagesbudgets. Dokumentierte Annahme, keine
     * Abrechnungsgrundlage.
     */
    public const ESTIMATED_TOKENS_PER_PAGE = 1500;

    /**
     * Grobe Schaetzung der Eingabetoken je Zeichen Text.
     */
    public const ESTIMATED_CHARS_PER_TOKEN = 4;

    public function __construct(
        protected readonly ProviderConfig $providerConfig,
        protected readonly AiHttpClientInterface $httpClient,
        protected readonly SchemaRegistry $schemas,
        protected readonly PromptRegistry $prompts,
        protected readonly JsonSchemaValidator $validator,
        protected readonly ConfidenceEvaluator $confidenceEvaluator,
        protected readonly CostEstimator $costEstimator,
        protected readonly DailyCostLimiter $costLimiter,
        protected readonly RedactingLogger $logger,
        protected readonly int $maxRetries = 2,
        protected readonly int $inlineMaxBytes = self::DEFAULT_INLINE_MAX_BYTES,
        protected readonly int $maxOutputTokens = 16000,
    ) {}

    // -----------------------------------------------------------------
    // Providerspezifische Teile
    // -----------------------------------------------------------------

    /**
     * Baut und versendet die eigentliche Anfrage und liefert die rohe
     * Modellantwort.
     */
    abstract protected function sendSchemaRequest(
        SchemaCallPlan $plan,
        ?ProviderFileHandle $fileHandle,
        ?string $repairInstruction,
    ): RawProviderResponse;

    /**
     * Legt eine temporaere Datei beim Provider an. Nur, wenn die Datei nicht
     * direkt in den Verarbeitungsrequest passt.
     */
    abstract protected function uploadProviderFile(DocumentPayload $document): ProviderFileHandle;

    /**
     * Loescht eine temporaere Providerdatei ueber die Loeschschnittstelle.
     */
    abstract protected function deleteProviderFile(ProviderFileHandle $handle): ProviderFileDeletionOutcome;

    /**
     * Anfrage des Healthchecks, die die Verfuegbarkeit des konfigurierten
     * Modells prueft. Enthaelt keine Dokumentinhalte.
     */
    abstract protected function healthCheckRequest(string $model): AiHttpRequest;

    /**
     * Ist der Fehlercode der Providerantwort ein Hinweis auf einen nicht
     * unterstuetzten Dateityp?
     */
    abstract protected function isUnsupportedFileErrorCode(string $errorCode): bool;

    // -----------------------------------------------------------------
    // Schnittstelle
    // -----------------------------------------------------------------

    public function providerKey(): string
    {
        return $this->providerConfig->key->value;
    }

    public function classifyDocument(ClassifyDocumentRequest $request): ClassificationResult
    {
        $schema = $this->schemas->get('dokumentklassifikation');

        $result = $this->executeSchemaCall(new SchemaCallPlan(
            AiCallPurpose::KLASSIFIKATION,
            $this->providerConfig->model(false),
            $this->prompts->classification($schema),
            $schema,
            $request->context,
            $request->document,
        ));

        return self::toClassificationResult($result);
    }

    public function extractStructuredData(ExtractStructuredDataRequest $request): ExtractionResult
    {
        $schema = $this->schemas->get($request->schemaKey);

        return $this->executeSchemaCall(new SchemaCallPlan(
            AiCallPurpose::EXTRAKTION,
            $this->providerConfig->model($this->usesAnalyzeModel($request->schemaKey)),
            $this->prompts->extraction($schema),
            $schema,
            $request->context,
            $request->document,
        ));
    }

    public function analyzeContract(AnalyzeContractRequest $request): ExtractionResult
    {
        $schema = $this->schemas->get('mietvertrag');

        return $this->executeSchemaCall(new SchemaCallPlan(
            AiCallPurpose::VERTRAGSANALYSE,
            $this->providerConfig->model(true),
            $this->prompts->contractAnalysis($schema),
            $schema,
            $request->context,
            $request->document,
        ));
    }

    public function analyzePriorStatement(AnalyzePriorStatementRequest $request): ExtractionResult
    {
        $schema = $this->schemas->get('vorjahresabrechnung');

        return $this->executeSchemaCall(new SchemaCallPlan(
            AiCallPurpose::VORJAHRESANALYSE,
            $this->providerConfig->model(true),
            $this->prompts->priorStatementAnalysis($schema),
            $schema,
            $request->context,
            $request->document,
        ));
    }

    public function reconcileDocuments(ReconcileDocumentsRequest $request): ReconciliationResult
    {
        $schema = $this->schemas->get('reconciliation');

        $result = $this->executeSchemaCall(new SchemaCallPlan(
            AiCallPurpose::RECONCILIATION,
            $this->providerConfig->model(true),
            $this->prompts->reconciliation($schema),
            $schema,
            $request->context,
            null,
            self::encodeReconciliationInput($request),
        ));

        return new ReconciliationResult($result);
    }

    public function healthCheck(HealthCheckRequest $request): HealthCheckResult
    {
        $model = $this->providerConfig->model($request->analyzeModel);
        $start = hrtime(true);

        if (! $this->providerConfig->hasApiKey()) {
            return new HealthCheckResult(
                $this->providerKey(),
                $model,
                false,
                false,
                false,
                null,
                0,
                $request->context->correlationId,
                'Es ist kein API-Key konfiguriert. Der Provider kann nicht geprueft werden.',
                false,
            );
        }

        try {
            $response = $this->httpClient->send($this->healthCheckRequest($model));
        } catch (Throwable) {
            return new HealthCheckResult(
                $this->providerKey(),
                $model,
                false,
                false,
                false,
                null,
                self::elapsedMs($start),
                $request->context->correlationId,
                'Der Provider ist technisch nicht erreichbar.',
                true,
            );
        }

        $durationMs = self::elapsedMs($start);
        $available = $response->isSuccessful();

        $message = match (true) {
            $available => 'Modell ist beim Provider verfuegbar.',
            $response->statusCode === 401 || $response->statusCode === 403 => 'Der API-Key wurde vom Provider abgelehnt.',
            $response->statusCode === 404 => 'Das konfigurierte Modell ist beim Provider nicht verfuegbar.',
            $response->isRateLimited() => 'Der Provider hat die Pruefung wegen einer Ratenbegrenzung abgelehnt.',
            default => 'Der Provider hat die Pruefung mit einem technischen Fehler beantwortet.',
        };

        $result = new HealthCheckResult(
            $this->providerKey(),
            $model,
            true,
            $available,
            // Die Freigabe ist keine Providereigenschaft. Sie wird vom
            // AiProviderRouter ueber ProviderReleaseGate ergaenzt.
            false,
            $response->statusCode,
            $durationMs,
            $request->context->correlationId,
            $message,
            true,
        );

        $this->logger->info('KI-Healthcheck ausgefuehrt', $result->toLogContext());

        return $result;
    }

    // -----------------------------------------------------------------
    // Ablauf
    // -----------------------------------------------------------------

    protected function executeSchemaCall(SchemaCallPlan $plan): ExtractionResult
    {
        $this->assertMimeTypeSupported($plan->document);
        $this->assertDailyBudget($plan);

        $start = hrtime(true);
        $fileHandle = null;
        $deletions = [];

        $attempts = 0;
        $inputTokens = 0;
        $outputTokens = 0;
        $httpStatusCode = null;
        $requestIds = [];
        $repairInstruction = null;

        /** @var list<SchemaViolation> $violations */
        $violations = [];

        /** @var array<string, mixed> $data */
        $data = [];

        /** @var array<string, ExtractedValue> $fields */
        $fields = [];

        $validated = false;

        try {
            if ($plan->document !== null && ! $this->canSendInline($plan->document)) {
                $this->notifyBeforeRequest($plan);

                $fileHandle = $this->uploadDocumentForPlan($plan, $plan->document);

                // Die ID wird sofort gemeldet, damit sie waehrend der
                // Verarbeitung ausserhalb des Arbeitsspeichers nachverfolgbar
                // ist. Scheitert die Meldung, loescht der finally-Zweig die
                // Datei wieder: keine Providerdatei ohne Nachweis.
                $plan->context->observer?->providerFileCreated($this->providerKey(), $fileHandle->fileId);
            }

            for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
                $attempts = $attempt + 1;

                $this->notifyBeforeRequest($plan);

                $raw = $this->sendSchemaRequest($plan, $fileHandle, $repairInstruction);

                $inputTokens += $raw->inputTokens;
                $outputTokens += $raw->outputTokens;
                $httpStatusCode = $raw->httpStatusCode;

                if ($raw->requestId !== null) {
                    $requestIds[] = $raw->requestId;
                }

                $outcome = $this->validator->validateJson($plan->schema, $raw->jsonPayload());

                // Die rohe Modellantwort wird hier verworfen. Ab dieser
                // Stelle existiert nur noch das validierte Ergebnis.
                unset($raw);

                if ($outcome->isValid()) {
                    $data = $outcome->data();
                    $fields = $this->confidenceEvaluator->mark($outcome->fields());
                    $violations = [];
                    $validated = true;

                    break;
                }

                $violations = $outcome->violations();
                $repairInstruction = $outcome->repairInstruction();

                $this->logger->warning('Schemaverletzung in Modellantwort, kontrollierter Reparaturversuch', [
                    'provider' => $this->providerKey(),
                    'model' => $plan->model,
                    'purpose' => $plan->purpose->value,
                    'schema_key' => $plan->schema->key,
                    'schema_version' => $plan->schema->version,
                    'attempt' => $attempts,
                    'attempts' => $this->maxRetries + 1,
                    'violation_count' => count($violations),
                    'violation_codes' => $outcome->violationCodes(),
                    'violation_paths' => $outcome->violationPaths(),
                    'correlation_id' => $plan->context->correlationId,
                ]);
            }
        } catch (Throwable $exception) {
            // Wurde bereits mindestens ein Verarbeitungsrequest gesendet, ist
            // Verbrauch entstanden, der nicht verloren gehen darf.
            if ($attempts > 0) {
                $abortedEstimate = $this->costEstimator->estimate($plan->model, $inputTokens, $outputTokens);

                $plan->context->observer?->providerCallAborted(new AiCallMetadata(
                    $this->providerKey(),
                    $plan->model,
                    $plan->purpose,
                    $exception instanceof RateLimitException ? AiCallStatus::RATE_LIMIT : AiCallStatus::TECHNISCHER_FEHLER,
                    $plan->prompt->version,
                    $plan->prompt->hash(),
                    $plan->schema->key,
                    $plan->schema->version,
                    $plan->schema->hash(),
                    $inputTokens,
                    $outputTokens,
                    $abortedEstimate->costCent(),
                    $abortedEstimate->costMilliCentOrNull(),
                    $abortedEstimate->basisAvailable,
                    self::elapsedMs($start),
                    $requestIds === [] ? null : $requestIds[array_key_last($requestIds)],
                    $requestIds,
                    $plan->context->correlationId,
                    $attempts,
                    $httpStatusCode,
                ));
            }

            throw $exception;
        } finally {
            $deletions[] = $fileHandle === null
                ? ProviderFileDeletionOutcome::notRequired($this->providerKey())
                : $this->deleteProviderFileSafely($plan, $fileHandle);
        }

        $estimate = $this->costEstimator->estimate($plan->model, $inputTokens, $outputTokens);

        $metadata = new AiCallMetadata(
            $this->providerKey(),
            $plan->model,
            $plan->purpose,
            $validated ? AiCallStatus::ERFOLGREICH : AiCallStatus::SCHEMA_FEHLER,
            $plan->prompt->version,
            $plan->prompt->hash(),
            $plan->schema->key,
            $plan->schema->version,
            $plan->schema->hash(),
            $inputTokens,
            $outputTokens,
            $estimate->costCent(),
            $estimate->costMilliCentOrNull(),
            $estimate->basisAvailable,
            self::elapsedMs($start),
            $requestIds === [] ? null : $requestIds[array_key_last($requestIds)],
            $requestIds,
            $plan->context->correlationId,
            $attempts,
            $httpStatusCode,
        );

        $result = new ExtractionResult(
            $validated ? AiResultStatus::VALIDIERT : AiResultStatus::FEHLGESCHLAGEN,
            $data,
            $fields,
            $metadata,
            $violations,
            $deletions,
        );

        $this->logger->info(
            $validated
                ? 'KI-Aufruf validiert'
                : 'KI-Aufruf nach Reparaturversuchen nicht schemakonform, manuelle Erfassung erforderlich',
            $metadata->toLogContext() + [
                'review_required_count' => count($result->reviewRequiredPaths()),
                'missing_value_count' => count($result->missingPaths()),
                'violation_count' => count($violations),
                'deletion_status' => $deletions[0]->status->value,
            ],
        );

        return $result;
    }

    // -----------------------------------------------------------------
    // Hilfsmittel fuer die Provider
    // -----------------------------------------------------------------

    /**
     * Prueft die Providerantwort und wirft die passende Ausnahme.
     *
     * VERBINDLICH: Der Antwortbody wird nicht in die Ausnahme uebernommen.
     * Uebernommen wird nur der maschinenlesbare Fehlercode.
     */
    protected function guardResponse(AiHttpResponse $response): void
    {
        if ($response->isSuccessful()) {
            return;
        }

        if ($response->isRateLimited()) {
            throw RateLimitException::forProvider($this->providerKey(), $response->retryAfterSeconds());
        }

        $errorCode = $response->errorCode();

        if ($errorCode !== null && $this->isUnsupportedFileErrorCode($errorCode)) {
            throw UnsupportedFileTypeException::forMimeType($this->providerKey(), $errorCode);
        }

        throw ProviderTransportException::httpStatus($this->providerKey(), $response->statusCode, $errorCode);
    }

    protected function canSendInline(DocumentPayload $document): bool
    {
        return $document->byteSize() <= $this->inlineMaxBytes;
    }

    protected function usesAnalyzeModel(string $schemaKey): bool
    {
        return in_array($schemaKey, static::ANALYZE_MODEL_SCHEMAS, true);
    }

    /**
     * Nutzeranweisung inklusive Reparaturhinweis.
     *
     * Der Reparaturhinweis enthaelt ausschliesslich Schemapfade und
     * Verletzungscodes.
     */
    protected function buildUserInstruction(SchemaCallPlan $plan, ?string $repairInstruction): string
    {
        $instruction = $plan->prompt->userInstruction;

        $textInput = $plan->textInput();

        if ($textInput !== null) {
            $instruction .= "\n\nStrukturierte Extraktionsdaten der Quellen:\n".$textInput;
        }

        if ($repairInstruction !== null && $repairInstruction !== '') {
            $instruction .= "\n\nDie vorige Antwort war nicht schemakonform. Korrigiere ausschliesslich die "
                ."folgenden Punkte und gib das vollstaendige JSON-Objekt erneut aus:\n"
                .$repairInstruction;
        }

        return $instruction;
    }

    protected static function elapsedMs(int|float $startHrtime): int
    {
        return (int) round((hrtime(true) - $startHrtime) / 1_000_000);
    }

    // -----------------------------------------------------------------
    // Interne Hilfsmittel
    // -----------------------------------------------------------------

    private function assertMimeTypeSupported(?DocumentPayload $document): void
    {
        if ($document === null) {
            return;
        }

        if ($this->supportsMimeType($document->mimeType)) {
            return;
        }

        // Lokale Pruefung vor dem Versand: es wird kein Dokumentinhalt
        // uebertragen, nur um eine Ablehnung zu erhalten.
        throw UnsupportedFileTypeException::forMimeType($this->providerKey(), $document->mimeType);
    }

    private function assertDailyBudget(SchemaCallPlan $plan): void
    {
        if (! $this->costLimiter->isEnabled()) {
            return;
        }

        $estimatedInputTokens = $plan->context->estimatedInputTokens ?? $this->estimateInputTokens($plan);

        $estimate = $this->costEstimator->estimateWorstCase(
            $plan->model,
            $estimatedInputTokens,
            $this->maxOutputTokens,
        );

        if (! $estimate->basisAvailable) {
            // Ohne Kalkulationsbasis wird nichts geraten. Bei aktivem
            // Tagesbudget wird der Aufruf verweigert, statt ungezaehlt
            // durchzulaufen; der Betreiber erhaelt eine klare Meldung.
            $this->logger->error('Keine Kalkulationsbasis fuer Modell konfiguriert, Aufruf bei aktivem Tagesbudget verweigert', [
                'provider' => $this->providerKey(),
                'model' => $plan->model,
                'purpose' => $plan->purpose->value,
                'cost_basis_available' => false,
                'correlation_id' => $plan->context->correlationId,
            ]);

            throw CostBasisMissingException::forModel($this->providerKey(), $plan->model);
        }

        $this->costLimiter->assertWithinLimit(
            $plan->context->dailySpentMilliCent,
            $estimate->costMilliCent,
        );
    }

    /**
     * Grobe Schaetzung der Eingabetoken fuer die Budgetvorabpruefung.
     *
     * DOKUMENTIERTE ANNAHME: Es handelt sich um eine bewusst konservative
     * Schaetzung und nicht um eine Tokenzaehlung. Der Aufrufer kann eine
     * genauere Schaetzung ueber AiRequestContext::estimatedInputTokens
     * uebergeben.
     */
    private function estimateInputTokens(SchemaCallPlan $plan): int
    {
        $tokens = (int) ceil(mb_strlen($plan->prompt->systemPrompt) / self::ESTIMATED_CHARS_PER_TOKEN);
        $tokens += (int) ceil(mb_strlen(json_encode($plan->schema->jsonSchema()) ?: '') / self::ESTIMATED_CHARS_PER_TOKEN);

        $textInput = $plan->textInput();

        if ($textInput !== null) {
            $tokens += (int) ceil(mb_strlen($textInput) / self::ESTIMATED_CHARS_PER_TOKEN);
        }

        if ($plan->document !== null) {
            $pages = $plan->document->pageCount ?? 1;
            $tokens += max(1, $pages) * self::ESTIMATED_TOKENS_PER_PAGE;
        }

        return $tokens;
    }

    private function uploadDocumentForPlan(SchemaCallPlan $plan, DocumentPayload $document): ProviderFileHandle
    {
        if (! $plan->context->allowProviderFileUpload) {
            // Kein Dateiformatfehler: eine fruehere Providerdatei ist noch
            // offen. Der Aufruf wartet auf die Wiederholung der Loeschung.
            throw ProviderFileNotReleasedException::uploadBlocked($this->providerKey());
        }

        // Zweite Pruefung mit aktuellem Stand: Der Kontextwert wurde vor dem
        // ersten Aufruf berechnet. Ein vorangegangener Aufruf desselben
        // Vorgangs (Schema-Fallback) kann inzwischen eine Datei hinterlassen
        // haben, deren Loeschung nicht bestaetigt ist. Dann wird gar nicht
        // erst hochgeladen, statt die zweite Datei nach dem Upload sofort
        // wieder zu loeschen.
        if ($plan->context->observer?->mayCreateProviderFile($this->providerKey()) === false) {
            throw ProviderFileNotReleasedException::uploadBlocked($this->providerKey());
        }

        return $this->uploadProviderFile($document);
    }

    private function deleteProviderFileSafely(SchemaCallPlan $plan, ProviderFileHandle $handle): ProviderFileDeletionOutcome
    {
        try {
            $this->notifyBeforeRequest($plan);

            $outcome = $this->deleteProviderFile($handle);
        } catch (Throwable) {
            $outcome = ProviderFileDeletionOutcome::failed(
                $this->providerKey(),
                $handle->fileId,
                'loeschaufruf_fehlgeschlagen',
            );
        }

        if ($outcome->isPrivacyAlert()) {
            $this->logger->error(
                'Loeschung einer temporaeren Providerdatei fehlgeschlagen, Datenschutzalarm',
                $outcome->toLogContext(),
            );
        }

        $plan->context->observer?->providerFileReleased($this->providerKey(), $handle->fileId, $outcome);

        return $outcome;
    }

    /**
     * Heartbeat vor jedem einzelnen HTTP-Request, damit das Lease des
     * laufenden Teiljobs einen langen Aufruf mit mehreren Requests ueberdauert.
     */
    private function notifyBeforeRequest(SchemaCallPlan $plan): void
    {
        $plan->context->observer?->beforeProviderRequest($this->providerKey());
    }

    /**
     * Baut aus dem Klassifikationsergebnis das typisierte Ergebnis-DTO.
     */
    private static function toClassificationResult(ExtractionResult $result): ClassificationResult
    {
        $typeField = $result->field('dokumenttyp');
        $rawType = $typeField?->value;

        $documentType = is_string($rawType) ? DocumentType::tryFrom($rawType) : null;

        $instructionField = $result->field('enthaelt_anweisungstext');

        $alternatives = [];
        $rawAlternatives = $result->data['alternative_dokumenttypen'] ?? [];

        if (is_array($rawAlternatives)) {
            foreach ($rawAlternatives as $alternative) {
                if (! is_array($alternative)) {
                    continue;
                }

                $type = $alternative['dokumenttyp']['value'] ?? null;
                $reason = $alternative['begruendung']['value'] ?? null;

                $alternatives[] = [
                    'dokumenttyp' => is_string($type) ? $type : null,
                    'begruendung' => is_string($reason) ? $reason : null,
                ];
            }
        }

        return new ClassificationResult(
            $result,
            $documentType,
            $typeField->confidence ?? 0.0,
            $alternatives,
            $instructionField?->value === true,
        );
    }

    /**
     * Serialisiert die bereits validierten Extraktionsdaten der Quellen.
     *
     * Es werden nur die neutrale Quellenbezeichnung, der Dokumenttyp, der
     * Schemaschluessel und die strukturierten Werte uebergeben. Es wird keine
     * Originaldatei erneut versendet.
     */
    private static function encodeReconciliationInput(ReconcileDocumentsRequest $request): string
    {
        $payload = [
            'abrechnungszeitraum_von' => $request->periodFrom,
            'abrechnungszeitraum_bis' => $request->periodTo,
            'toleranz_cent' => $request->toleranceCent,
            'quellen' => array_map(
                static fn (ReconciliationSubject $subject): array => [
                    'quellenbezeichnung' => $subject->neutralLabel,
                    'dokumenttyp' => $subject->documentType->value,
                    'schema' => $subject->schemaKey,
                    'werte' => $subject->structuredData,
                ],
                $request->subjects,
            ),
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
