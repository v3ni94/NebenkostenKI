<?php

declare(strict_types=1);

namespace App\Domain\Calculation;

use App\Domain\Period\DatePeriodRange;

/**
 * Ein Beteiligter der Kostenverteilung: ein auf den Abrechnungszeitraum
 * begrenzter Nutzungszeitraum einer Einheit.
 *
 * Die Beteiligten einer Einheit überdecken den Abrechnungszeitraum
 * lückenlos und überschneidungsfrei. Nicht belegte Zeiträume werden von der
 * Engine als OWNER_RESIDUAL ergänzt, damit Kosten niemals stillschweigend
 * auf die übrigen Mieter verschoben werden.
 */
final readonly class AllocationParticipant
{
    public function __construct(
        public string $participantKey,
        public string $unitKey,
        public OccupancyKind $kind,
        public DatePeriodRange $period,
        public string $label,
    ) {}

    public function days(): int
    {
        return $this->period->days();
    }

    public function isTenancy(): bool
    {
        return $this->kind === OccupancyKind::TENANCY;
    }

    public function isChargedToOwner(): bool
    {
        return $this->kind->isChargedToOwner();
    }
}
