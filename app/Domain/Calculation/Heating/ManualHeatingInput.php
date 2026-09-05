<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * Eingabedaten der manuellen Heizkostenerfassung (Fall B).
 *
 * Der Gesamtbetrag ist optional. Ist er erfasst, wird die Summe der
 * Einzelbetraege dagegen geprueft. Fehlt er, entfaellt die Gegenprobe; das
 * Ergebnis weist darauf ausdruecklich hin (kein stiller Verzicht auf die
 * Pruefung).
 *
 * Die Herkunft der Berechnung ist ein Freitext des Anwenders. Sie erscheint
 * ausschliesslich im internen Blatt der Eigentuemeruebersicht.
 */
final readonly class ManualHeatingInput
{
    /**
     * @param  array<string, ManualHeatingEntry>  $entriesByUnit  Einheitenschluessel => erfasste Betraege
     */
    public function __construct(
        public DatePeriodRange $period,
        public array $entriesByUnit,
        public ?Money $declaredTotal = null,
        public ?string $calculationOrigin = null,
    ) {}

    /**
     * Summe aller erfassten Betraege, einschliesslich des CO2-Anteils des
     * Vermieters.
     */
    public function sumOfRecordedAmounts(): Money
    {
        $total = Money::zero();

        foreach ($this->entriesByUnit as $entry) {
            $total = $total->plus($entry->recordedAmount());
        }

        return $total;
    }

    /**
     * Summe der auf die Mieter zu verteilenden Betraege.
     */
    public function sumOfTenantAmounts(): Money
    {
        $total = Money::zero();

        foreach ($this->entriesByUnit as $entry) {
            $total = $total->plus($entry->tenantAmount());
        }

        return $total;
    }

    /**
     * Einheiten ohne erfasste Betraege. Sie werden niemals geschaetzt.
     *
     * @return list<string>
     */
    public function unitsWithoutAmounts(): array
    {
        $units = [];

        foreach ($this->entriesByUnit as $unitKey => $entry) {
            if ($entry->isEmpty()) {
                $units[] = (string) $unitKey;
            }
        }

        return $units;
    }

    public function hasDeclaredTotal(): bool
    {
        return $this->declaredTotal instanceof Money;
    }
}
