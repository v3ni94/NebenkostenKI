<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Anfrage des Admin-Healthchecks.
 *
 * Prueft, ob das konfigurierte Modell beim Provider tatsaechlich verfuegbar
 * ist (Abschnitt 13.2). Der Healthcheck sendet keine Dokumentinhalte.
 */
final class HealthCheckRequest
{
    public function __construct(
        public readonly AiRequestContext $context,
        /**
         * true prueft das Analysemodell, false das Extraktionsmodell.
         */
        public readonly bool $analyzeModel = false,
    ) {}
}
