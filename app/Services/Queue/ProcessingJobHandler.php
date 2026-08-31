<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Models\ProcessingJob;

/**
 * Bearbeiter eines Teiljobs.
 *
 * VERBINDLICHE ANFORDERUNGEN an jede Umsetzung (ADR-006):
 *
 * 1. Idempotent. Ein zweiter Aufruf mit demselben Job darf keinen zusaetzlichen
 *    Effekt haben, weil ein Lease ablaufen und ein Job erneut anlaufen kann.
 * 2. Kurz. Ein Teilschritt muss innerhalb eines Cron-Laufs mit
 *    --max-time=50 abschliessen oder sich in weitere Teiljobs zerlegen.
 * 3. Datensparsam. Der Handler liest seine Eingaben ausschliesslich anhand der
 *    Referenz-IDs aus dem Payload und schreibt keine Inhalte zurueck.
 */
interface ProcessingJobHandler
{
    /**
     * @throws JobFailedException bei kontrolliertem Scheitern
     */
    public function handle(ProcessingJob $job, JobContext $context): void;
}
