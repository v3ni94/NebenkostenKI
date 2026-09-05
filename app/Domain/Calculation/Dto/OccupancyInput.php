<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Dto;

use App\Domain\Allocation\PersonDaysSegment;
use App\Domain\Calculation\OccupancyKind;
use App\Domain\Period\DatePeriodRange;

/**
 * Ein Nutzungszeitraum einer Einheit: Mietverhältnis oder Leerstand.
 *
 * Der Zeitraum ist taggenau mit inklusivem Start- und Endtag. Zeiträume
 * außerhalb des Abrechnungszeitraums werden von der Engine auf den
 * Abrechnungszeitraum begrenzt.
 */
final readonly class OccupancyInput
{
    /**
     * @param  string  $occupancyKey  eindeutiger Schlüssel des Nutzungszeitraums
     * @param  list<PersonDaysSegment>  $personSegments  Personenabschnitte für Personen- und Personentageschlüssel
     */
    public function __construct(
        public string $occupancyKey,
        public string $unitKey,
        public DatePeriodRange $period,
        public OccupancyKind $kind = OccupancyKind::TENANCY,
        public string $label = '',
        public array $personSegments = [],
        public ?string $deliveryAddress = null,
    ) {}

    public static function tenancy(
        string $occupancyKey,
        string $unitKey,
        DatePeriodRange $period,
        string $label = '',
        ?string $deliveryAddress = null,
    ): self {
        return new self($occupancyKey, $unitKey, $period, OccupancyKind::TENANCY, $label, [], $deliveryAddress);
    }

    public static function vacancy(string $occupancyKey, string $unitKey, DatePeriodRange $period, string $label = 'Leerstand'): self
    {
        return new self($occupancyKey, $unitKey, $period, OccupancyKind::VACANCY, $label);
    }

    /**
     * @param  list<PersonDaysSegment>  $segments
     */
    public function withPersonSegments(array $segments): self
    {
        return new self(
            $this->occupancyKey,
            $this->unitKey,
            $this->period,
            $this->kind,
            $this->label,
            $segments,
            $this->deliveryAddress
        );
    }

    public function isChargedToOwner(): bool
    {
        return $this->kind->isChargedToOwner();
    }
}
