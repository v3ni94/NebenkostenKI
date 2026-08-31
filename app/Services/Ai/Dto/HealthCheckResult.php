<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Ergebnis des Admin-Healthchecks.
 *
 * Prueft, ob das konfigurierte Modell beim Provider tatsaechlich verfuegbar
 * ist (Abschnitt 13.2) und ob der Provider fuer den produktiven Einsatz
 * freigegeben ist.
 *
 * releasedForProduction ist bewusst getrennt von modelAvailable. Ein
 * verfuegbares Modell ohne Datenschutzfreigabe darf produktiv nicht genutzt
 * werden. Das Setzen von store=false oder ein Loeschaufruf allein ist keine
 * Zero Data Retention.
 */
final class HealthCheckResult
{
    public function __construct(
        public readonly string $providerKey,
        public readonly string $model,
        public readonly bool $reachable,
        public readonly bool $modelAvailable,
        public readonly bool $releasedForProduction,
        public readonly ?int $httpStatusCode = null,
        public readonly int $durationMs = 0,
        public readonly string $correlationId = '',
        /**
         * Sachliche technische Meldung in deutscher Sprache. Enthaelt keinen
         * Dokumentinhalt und keinen rohen Antwortbody.
         */
        public readonly string $message = '',
        public readonly bool $apiKeyConfigured = false,
    ) {}

    public function isUsable(): bool
    {
        return $this->reachable && $this->modelAvailable && $this->releasedForProduction;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toLogContext(): array
    {
        return [
            'provider' => $this->providerKey,
            'model' => $this->model,
            'reachable' => $this->reachable,
            'model_available' => $this->modelAvailable,
            'released_for_production' => $this->releasedForProduction,
            'status_code' => $this->httpStatusCode,
            'duration_ms' => $this->durationMs,
            'correlation_id' => $this->correlationId,
            'api_key_configured' => $this->apiKeyConfigured,
        ];
    }
}
