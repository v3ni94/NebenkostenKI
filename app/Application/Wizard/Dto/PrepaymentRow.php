<?php

declare(strict_types=1);

namespace App\Application\Wizard\Dto;

use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * Eine Zeile der Vorauszahlungsmaske (Masterprompt Abschnitt 9, Schritt 7).
 *
 * Fachliche Regel: Abgezogen werden ausschließlich die tatsächlich geleisteten
 * Vorauszahlungen. Der Sollwert dient der Plausibilisierung. Ist gleich Soll
 * darf vorgeschlagen werden, muss aber sichtbar als Annahme bestätigt und
 * protokolliert werden.
 */
final readonly class PrepaymentRow
{
    public function __construct(
        public string $tenancyId,
        public string $tenantLabel,
        public string $unitLabel,
        public DatePeriodRange $usagePeriod,
        public ?Money $monthlyOperating,
        public ?Money $monthlyHeating,
        public bool $heatingSeparate,
        public Money $targetTotal,
        public ?Money $actualTotal,
        public bool $assumedFromTarget,
        public string $sourceLabel,
        public bool $confirmed,
        public string $targetExplanation,
    ) {}

    public function usageDays(): int
    {
        return $this->usagePeriod->days();
    }

    /**
     * Fehlt der Ist-Wert und ist auch keine Annahme bestätigt, ist die Zeile
     * offen. Schritt 7 ist ein Pflichtschritt.
     */
    public function isOpen(): bool
    {
        return ! $this->assumedFromTarget && ! $this->actualTotal instanceof Money;
    }

    public function deviation(): ?Money
    {
        return $this->actualTotal instanceof Money
            ? $this->actualTotal->minus($this->targetTotal)
            : null;
    }

    public function hasDeviation(): bool
    {
        $deviation = $this->deviation();

        return $deviation instanceof Money && ! $deviation->isZero();
    }
}
