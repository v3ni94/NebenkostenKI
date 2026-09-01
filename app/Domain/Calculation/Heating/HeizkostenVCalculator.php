<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

/**
 * Fall B: Zentralheizung ohne externen Abrechner.
 *
 * EINE EIGENBERECHNUNG NACH HEIZKOSTENVERORDNUNG IST BEWUSST NICHT TEIL DES
 * LEISTUNGSUMFANGS. Sie ist nicht geplant und wird nicht freigeschaltet. Die
 * Verantwortung fuer die verbrauchsabhaengige Verteilung liegt beim Vermieter
 * beziehungsweise seinem Messdienstleister.
 *
 * Fall B wird ausschliesslich ueber die manuelle Erfassung abgedeckt: Der
 * Anwender ermittelt die Verteilung nach Grund- und Verbrauchskosten sowie die
 * CO2-Kostenaufteilung ausserhalb der Plattform und traegt die
 * Ergebnisbetraege je Einheit ein. Die Plattform uebernimmt diese Betraege
 * unveraendert als Direktzuordnung, rechnet sie nicht nach und verteilt sie
 * nicht selbst (siehe ManualHeatingReconciler und
 * App\Application\Heating\StoreManualHeatingEntries).
 *
 * Diese Klasse bleibt als Vollstaendigkeitspruefung bestehen. Sie benennt,
 * welche Angaben einer Eigenberechnung fehlen wuerden, und wird fuer
 * Hinweistexte und Pruefaufgaben verwendet. Sie rechnet nichts und wirft keine
 * Ausnahme, die eine kuenftige Freischaltung suggeriert.
 */
final class HeizkostenVCalculator
{
    /**
     * Benennt alle Angaben, die einer Eigenberechnung nach
     * Heizkostenverordnung fehlen wuerden.
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
     * Waere die Datenlage fuer eine Eigenberechnung vollstaendig? Auch dann
     * rechnet die Plattform nicht selbst.
     */
    public function hasCompleteData(HeizkostenVInput $input): bool
    {
        return $this->missingFields($input) === [];
    }

    /**
     * Zulaessiger Rahmen des Grundkostenanteils in Prozent. Reine
     * Hinweisangabe fuer die Oberflaeche.
     *
     * @return array{0: int, 1: int}
     */
    public function allowedBasicCostRange(): array
    {
        return [30, 50];
    }
}
