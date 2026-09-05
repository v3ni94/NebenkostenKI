<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * Eingabedaten einer externen Heizkostenabrechnung (Fall A).
 *
 * Die Einzelbeträge sind je Beteiligtem (Mietverhältnis oder Leerstand)
 * angegeben, damit ein Mieterwechsel korrekt abgebildet wird. Die
 * Gesamtsumme stammt aus der Abrechnung selbst und wird gegen die Summe der
 * Einzelbeträge geprüft.
 */
final readonly class ExternalHeatingStatementInput
{
    /**
     * @param  array<string, Money>  $amountsByParticipant  Nutzungszeitraum => Betrag
     */
    public function __construct(
        public string $provider,
        public DatePeriodRange $period,
        public Money $totalAmount,
        public array $amountsByParticipant,
        public Co2AllocationStatus $co2Status = Co2AllocationStatus::UNKNOWN,
        public ?Money $warmWaterShare = null,
    ) {}

    public function sumOfParticipantAmounts(): Money
    {
        return Money::sumOf($this->amountsByParticipant);
    }

    /**
     * Abweichung der Einzelbeträge gegenüber der ausgewiesenen Gesamtsumme.
     */
    public function difference(): Money
    {
        return $this->sumOfParticipantAmounts()->minus($this->totalAmount);
    }
}
