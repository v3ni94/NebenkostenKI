<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

use App\Domain\Allocation\DirectAssignmentKey;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Calculation\Rounding\LargestRemainderDistributor;
use App\Domain\Money\Money;
use Brick\Math\BigRational;

/**
 * Fall B: Zentralheizung ohne externen Abrechner, manuelle Erfassung.
 *
 * Der Anwender traegt die von ihm selbst ermittelten Betraege je Einheit ein.
 * Diese Klasse
 *
 *  1. bildet die Pruefsumme gegen einen optional erfassten Gesamtbetrag,
 *  2. erzeugt die Direktzuordnung je Einheit auf demselben Weg wie Fall A,
 *  3. verteilt den Betrag einer Einheit bei Mieterwechsel zeitanteilig nach
 *     Nutzungstagen ueber das Largest-Remainder-Verfahren der Domain.
 *
 * Sie rechnet die eingetragenen Betraege ausdruecklich NICHT nach und prueft
 * weder die Aufteilung in Grund- und Verbrauchskosten noch die
 * CO2-Kostenaufteilung. Verantwortlich fuer die Richtigkeit der Werte ist der
 * Vermieter.
 */
final class ManualHeatingReconciler
{
    public function __construct(private readonly LargestRemainderDistributor $distributor) {}

    public function reconcile(ManualHeatingInput $input, Money $tolerance): ManualHeatingResult
    {
        $recorded = $input->sumOfRecordedAmounts();
        $tenantSum = $input->sumOfTenantAmounts();

        $findings = [];
        $hint = null;
        $difference = null;
        $withinTolerance = true;
        $checksumAvailable = $input->hasDeclaredTotal();

        if (! $checksumAvailable) {
            $hint = 'Es ist kein Gesamtbetrag der Heizkosten erfasst. Ohne Gesamtbetrag ist keine Gegenprobe der '
                .'Einzelbeträge möglich. Die erfassten Beträge werden unverändert übernommen.';
        } else {
            $declared = $input->declaredTotal;

            if (! $declared instanceof Money) {
                $declared = Money::zero();
            }

            $difference = $recorded->minus($declared);
            $withinTolerance = $difference->absolute()->compareTo($tolerance->absolute()) <= 0;

            if ($withinTolerance) {
                $findings[] = CheckFinding::passed(
                    CheckCode::HEATING_CHECKSUM_WITHIN_TOLERANCE,
                    sprintf(
                        'Die erfassten Heizkosten je Einheit (%s) stimmen mit dem erfassten Gesamtbetrag (%s) im '
                        .'Rahmen der Toleranz von %s überein. Abweichung: %s.',
                        $recorded->format(),
                        $declared->format(),
                        $tolerance->format(),
                        $difference->format()
                    ),
                    ['differenceCent' => $difference->cents, 'quelle' => 'manuell erfasst']
                );
            } else {
                $findings[] = CheckFinding::blocker(
                    CheckCode::HEATING_CHECKSUM_OUT_OF_TOLERANCE,
                    sprintf(
                        'Die erfassten Heizkosten je Einheit (%s) weichen um %s vom erfassten Gesamtbetrag (%s) ab. '
                        .'Die Toleranz von %s ist überschritten. Bitte erklären oder korrigieren Sie die '
                        .'Abweichung; solange sie offen ist, kann die Abrechnung nicht abgeschlossen werden.',
                        $recorded->format(),
                        $difference->absolute()->format(),
                        $declared->format(),
                        $tolerance->format()
                    ),
                    ['differenceCent' => $difference->cents, 'quelle' => 'manuell erfasst']
                );
            }
        }

        return new ManualHeatingResult(
            $input->declaredTotal,
            $recorded,
            $tenantSum,
            $difference,
            $tolerance,
            $checksumAvailable,
            $withinTolerance,
            $findings,
            $withinTolerance ? $this->allocationKey($input) : null,
            $hint,
        );
    }

    /**
     * Direktzuordnung je Einheit aus den auf die Mieter zu verteilenden
     * Betraegen. Technisch derselbe Weg wie in Fall A.
     */
    public function allocationKey(ManualHeatingInput $input): ?DirectAssignmentKey
    {
        $amounts = [];

        foreach ($input->entriesByUnit as $unitKey => $entry) {
            if ($entry->tenantAmount()->isZero()) {
                continue;
            }

            $amounts[(string) $unitKey] = $entry->tenantAmount();
        }

        return $amounts === [] ? null : DirectAssignmentKey::fromAmounts($amounts);
    }

    /**
     * Zeitanteilige Verteilung des Betrages einer Einheit auf die
     * Nutzungszeitraeume, wenn innerhalb der Einheit ein Mieterwechsel liegt.
     *
     * Gerechnet wird ausschliesslich in ganzen Cent nach dem
     * Largest-Remainder-Verfahren. Die Summe der Teilbetraege entspricht
     * exakt dem Ausgangsbetrag.
     *
     * @param  array<string, int>  $daysByOccupancy  Nutzungszeitraum => Nutzungstage
     * @return array<string, Money>
     */
    public function splitByUsageDays(Money $amount, array $daysByOccupancy): array
    {
        $weights = [];

        foreach ($daysByOccupancy as $occupancyKey => $days) {
            if ($days <= 0) {
                continue;
            }

            $weights[(string) $occupancyKey] = BigRational::nd($days, 1);
        }

        if ($weights === []) {
            return [];
        }

        if (count($weights) === 1) {
            $only = array_key_first($weights);

            return [(string) $only => $amount];
        }

        $distribution = $this->distributor->distributeProportionally($amount->cents, $weights);

        $shares = [];

        foreach (array_keys($weights) as $occupancyKey) {
            $shares[$occupancyKey] = Money::fromCents($distribution->amountFor($occupancyKey));
        }

        return $shares;
    }
}
