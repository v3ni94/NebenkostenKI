<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

use App\Enums\AiCallStatus;
use App\Models\AiCall;
use App\Models\AiPromptVersion;
use App\Models\Document;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\Dto\AiCallMetadata;
use App\Services\Ai\RedactingLogger;

/**
 * Schreibt je KI-Aufruf einen Nachweisdatensatz in ai_calls
 * (Abschnitt 6.4 und 13.8).
 *
 * VERBINDLICH GESPEICHERT: Provider, Modell, Zweck, Promptversion,
 * Request-ID, Tokenzahlen, geschaetzte Kosten, Dauer, Status, Schemastatus,
 * Versuchszahl und Fehlercode.
 *
 * VERBINDLICH NICHT GESPEICHERT: Prompts, Systemprompts, Reparaturprompts,
 * rohe Modellantworten, Base64-Dateiinhalte, Fundstellentexte und temporaere
 * Provider-Datei-IDs. Die Tabelle hat dafuer bewusst keine Spalten; diese
 * Klasse fuellt ausschliesslich die freigegebenen Felder aus AiCallMetadata,
 * die selbst keine Inhalte fuehrt.
 *
 * error_message wird ausschliesslich aus einem festen deutschen Meldungssatz
 * dieser Anwendung gebildet, niemals aus einer Provider- oder Ausnahmemeldung.
 *
 * TESTPROVIDER: Der FakeAiProvider fuehrt keinen Netzwerkaufruf aus und ist
 * kein Provider im Sinne von App\Enums\AiProvider. Es gibt fuer ihn keinen
 * zulaessigen Wert der Spalte provider, und in ai_calls soll er nach der
 * ausdruecklichen Festlegung in AiProviderKey nicht als externe Verarbeitung
 * erscheinen. Fuer ihn wird deshalb kein Datensatz geschrieben; die
 * Promptversion wird trotzdem nachgefuehrt. Die extrahierten Felder tragen
 * dann keine ai_call_id, weil es keinen externen Aufruf gab, auf den sie
 * verweisen koennten.
 */
final class AiCallRecorder
{
    public function __construct(
        private readonly PromptVersionRegistrar $promptVersions,
        private readonly RedactingLogger $logger,
    ) {}

    /**
     * @param  int  $fileCount  Anzahl der in diesem Aufruf uebertragenen Dokumentdateien.
     */
    public function record(
        Document $document,
        AiCallMetadata $metadata,
        int $fileCount = 1,
        ?AiIntegrationErrorCode $errorCode = null,
    ): ?AiCall {
        $promptVersion = $this->promptVersions->register(
            $metadata->purpose,
            $metadata->promptVersion,
            $metadata->promptHash,
        );

        $this->logger->info('KI-Aufruf abgeschlossen', $metadata->toLogContext());

        $provider = AiProviderKey::tryFromKey($metadata->providerKey)?->toAiProviderEnum();

        if ($provider === null) {
            return null;
        }

        $call = new AiCall;

        $call->fill([
            'organization_id' => $document->getAttribute('organization_id'),
            'billing_run_id' => $document->getAttribute('billing_run_id'),
            'document_id' => $document->getKey(),
            'ai_prompt_version_id' => $promptVersion instanceof AiPromptVersion ? $promptVersion->getKey() : null,
            'provider' => $provider,
            'model' => mb_substr($metadata->model, 0, 120),
            'purpose' => $metadata->purpose,
            'request_id' => $this->requestId($metadata),
            'input_tokens' => max(0, $metadata->inputTokens),
            'output_tokens' => max(0, $metadata->outputTokens),
            'file_count' => max(0, $fileCount),
            'cost_cent' => $metadata->estimatedCostCent,
            'duration_ms' => max(0, $metadata->durationMs),
            'status' => $metadata->status,
            'schema_valid' => $metadata->status === AiCallStatus::ERFOLGREICH,
            'attempt' => max(1, $metadata->attempts),
            'error_code' => $errorCode?->value,
            'error_message' => $errorCode === null ? null : mb_substr($errorCode->message(), 0, 500),
        ]);

        $call->save();

        return $call;
    }

    /**
     * Technische Request-ID des Providers. Sie ist eine Referenz fuer den
     * Providersupport und enthaelt keinen Inhalt. Fehlt sie, bleibt die Spalte
     * null; es wird nichts erfunden.
     */
    private function requestId(AiCallMetadata $metadata): ?string
    {
        $requestId = $metadata->providerRequestId;

        if (! is_string($requestId) || trim($requestId) === '') {
            return null;
        }

        return mb_substr(trim($requestId), 0, 190);
    }
}
