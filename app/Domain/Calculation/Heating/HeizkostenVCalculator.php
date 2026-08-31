<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

/**
 * Fall B: Zentralheizung ohne externe Heizkostenabrechnung.
 *
 * Klar abgegrenztes, VORBEREITETES Modul für die Eigenberechnung nach
 * HeizkostenV. Der Rechenweg ist bewusst NICHT freigeschaltet:
 *
 * - Sind Daten unvollständig, wird IncompleteHeatingDataException geworfen
 *   und die fehlenden Angaben werden konkret benannt.
 * - Sind die Daten vollständig, wird HeatingCalculationNotReleasedException
 *   geworfen, solange das Modul fachlich nicht freigegeben ist.
 *
 * Damit ist ausgeschlossen, dass eine unfertige Automatik ein scheinbar
 * korrektes Ergebnis liefert (Pflichtenheft Abschnitt 12.3, Fall B).
 *
 * Geplanter Rechenweg nach Freischaltung (Reihenfolge verbindlich):
 * 1. Gesamtkosten der Wärmeversorgung bilden: Brennstoffkosten unter
 *    Berücksichtigung des Brennstoffbestands zu Beginn und zum Ende des
 *    Zeitraums, Betriebsstrom, Wartung, Messdienstkosten.
 * 2. Warmwasseranteil nach dem erfassten Verfahren von den Heizkosten
 *    trennen.
 * 3. Aufteilung in Grundkosten und Verbrauchskosten anhand des zulässigen
 *    Grundkostenanteils (Rahmen 30 bis 50 Prozent).
 * 4. Grundkosten nach beheizter Wohnfläche, Verbrauchskosten nach erfassten
 *    Verbrauchswerten verteilen.
 * 5. CO2-Kosten nach dem Stufenmodell anhand der Gebäudeemission je
 *    Quadratmeter aufteilen.
 * 6. Rundung ausschließlich am Ende jeder Kostenzeile mit dem
 *    Largest-Remainder-Verfahren, damit die Prüfsumme exakt bleibt.
 */
final class HeizkostenVCalculator
{
    /**
     * Freischaltungsschalter des Moduls. Erst nach vollständiger Umsetzung
     * und fachlicher Prüfung auf true setzen.
     */
    public const bool RELEASED = false;

    public function isReleased(): bool
    {
        return self::RELEASED;
    }

    /**
     * Eigenberechnung nach HeizkostenV.
     *
     * @throws IncompleteHeatingDataException bei unvollständigen Daten
     * @throws HeatingCalculationNotReleasedException solange das Modul nicht freigeschaltet ist
     */
    public function calculate(HeizkostenVInput $input): HeizkostenVResult
    {
        $missing = $this->missingFields($input);

        if ($missing !== []) {
            throw IncompleteHeatingDataException::missingFields($missing);
        }

        // Auch bei vollständigen Daten liefert das Modul bewusst kein Ergebnis,
        // solange der oben dokumentierte Rechenweg nicht umgesetzt und fachlich
        // freigegeben ist. Eine scheinbar korrekte Automatik ist ausgeschlossen.
        throw HeatingCalculationNotReleasedException::create();
    }

    /**
     * Benennt alle für eine vollständige Eigenberechnung fehlenden Angaben.
     *
     * @return list<string>
     */
    public function missingFields(HeizkostenVInput $input): array
    {
        $missing = [];

        if ($input->totalFuelCost === null) {
            $missing[] = 'Brennstoffkosten';
        }

        if ($input->operatingElectricityCost === null) {
            $missing[] = 'Betriebsstrom';
        }

        if ($input->basicCostPercentage === null) {
            $missing[] = 'Grundkostenanteil in Prozent';
        }

        if ($input->heatedAreaByUnit === []) {
            $missing[] = 'beheizte Wohnflächen je Einheit';
        }

        if ($input->heatingConsumptionByUnit === []) {
            $missing[] = 'erfasste Heizverbrauchswerte je Einheit';
        }

        if ($input->warmWaterConsumptionByUnit === []) {
            $missing[] = 'erfasste Warmwasserverbrauchswerte je Einheit';
        }

        if ($input->fuelInventoryStart === null || $input->fuelInventoryEnd === null) {
            $missing[] = 'Brennstoffbestand zu Beginn und zum Ende des Zeitraums';
        }

        if ($input->warmWaterEnergyShareMethod === null) {
            $missing[] = 'Verfahren zur Ermittlung des Warmwasseranteils';
        }

        if ($input->co2Cost === null) {
            $missing[] = 'CO2-Kosten';
        }

        if ($input->buildingCo2StepLevel === null) {
            $missing[] = 'Stufe des CO2-Stufenmodells';
        }

        return $missing;
    }

    /**
     * Ist die Datenlage für eine Eigenberechnung vollständig?
     */
    public function hasCompleteData(HeizkostenVInput $input): bool
    {
        return $this->missingFields($input) === [];
    }

    /**
     * Zulässiger Rahmen des Grundkostenanteils in Prozent.
     *
     * @return array{0: int, 1: int}
     */
    public function allowedBasicCostRange(): array
    {
        return [30, 50];
    }
}
