<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

use App\Domain\Money\Money;

/**
 * Manuell erfasste Heizkosten einer Einheit (Fall B).
 *
 * Fachliche Festlegung des Auftraggebers: Bei Zentralheizung ohne externen
 * Abrechner rechnet die Plattform NICHT selbst. Der Anwender ermittelt die
 * Verteilung nach Grund- und Verbrauchskosten sowie die CO2-Kostenaufteilung
 * ausserhalb der Plattform und traegt ausschliesslich die Ergebnisbetraege je
 * Einheit ein. Diese Betraege werden unveraendert uebernommen, nicht
 * nachgerechnet und nicht selbst verteilt.
 *
 * Betragsfelder:
 *  - heating       Heizung
 *  - warmWater     Warmwasser
 *  - co2Landlord   CO2-Kostenanteil des Vermieters
 *  - co2Tenant     CO2-Kostenanteil des Mieters
 *  - other         sonstige Kosten des Heizbetriebs
 *
 * Der CO2-Kostenanteil des Vermieters wird ausdruecklich NICHT auf den Mieter
 * umgelegt. Er wird nur erfasst, damit die Gegenprobe gegen einen
 * Gesamtbetrag vollstaendig ist, und im internen Blatt ausgewiesen.
 */
final readonly class ManualHeatingEntry
{
    public function __construct(
        public string $unitKey,
        public string $unitLabel,
        public Money $heating,
        public Money $warmWater,
        public Money $co2Landlord,
        public Money $co2Tenant,
        public Money $other,
    ) {}

    /**
     * Auf den Mieter zu verteilender Betrag der Einheit.
     */
    public function tenantAmount(): Money
    {
        return Money::sum($this->heating, $this->warmWater, $this->co2Tenant, $this->other);
    }

    /**
     * Alle erfassten Betraege der Einheit, einschliesslich des
     * CO2-Kostenanteils des Vermieters. Grundlage der Pruefsumme.
     */
    public function recordedAmount(): Money
    {
        return $this->tenantAmount()->plus($this->co2Landlord);
    }

    public function isEmpty(): bool
    {
        return $this->recordedAmount()->isZero();
    }
}
