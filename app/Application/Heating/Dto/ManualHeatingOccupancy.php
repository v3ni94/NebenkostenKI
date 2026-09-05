<?php

declare(strict_types=1);

namespace App\Application\Heating\Dto;

/**
 * Nutzungszeitraum einer Einheit innerhalb des Abrechnungszeitraums.
 *
 * Bei Mieterwechsel liegen je Einheit mehrere Nutzungszeitraeume vor. Der
 * erfasste Betrag der Einheit wird zeitanteilig nach Nutzungstagen verteilt.
 */
final readonly class ManualHeatingOccupancy
{
    public function __construct(
        public string $tenancyId,
        public string $label,
        public int $days,
        public string $periodLabel,
    ) {}
}
