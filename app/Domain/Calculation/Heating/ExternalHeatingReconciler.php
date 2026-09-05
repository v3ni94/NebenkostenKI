<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

use App\Domain\Allocation\DirectAssignmentKey;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Money\Money;

/**
 * Fall A: externe Heizkostenabrechnung liegt vor.
 *
 * Die Einzelbeträge je Nutzungszeitraum werden als Direktzuordnung
 * übernommen. Zuvor wird die Prüfsumme gegen den ausgewiesenen Gesamtbetrag
 * gebildet:
 *
 * - Abweichung innerhalb der Toleranz: Prüfergebnis PASSED, der
 *   Verteilerschlüssel wird erzeugt.
 * - Abweichung über der Toleranz: Prüfergebnis BLOCKER. Die Finalisierung ist
 *   gesperrt, bis der Nutzer die Abweichung erklärt oder korrigiert. Es wird
 *   KEINE Exception geworfen, damit der Prüfbericht die Zahlen zeigen kann.
 *
 * Ein unbekannter CO2-Status erzeugt zusätzlich eine Prüfaufgabe. Eine
 * doppelte Erfassung derselben Heizkosten aus einer WEG-Summenposition wird
 * in der Reconciliation der Hausgeldabrechnung behandelt
 * (siehe App\Domain\Calculation\Weg\HausgeldCostExtractor).
 */
final class ExternalHeatingReconciler
{
    public function reconcile(ExternalHeatingStatementInput $statement, Money $tolerance): HeatingReconciliationResult
    {
        $sum = $statement->sumOfParticipantAmounts();
        $difference = $statement->difference();
        $withinTolerance = $difference->absolute()->compareTo($tolerance->absolute()) <= 0;

        $findings = [];

        if ($withinTolerance) {
            $findings[] = CheckFinding::passed(
                CheckCode::HEATING_CHECKSUM_WITHIN_TOLERANCE,
                sprintf(
                    'Die Einzelbeträge der Heizkostenabrechnung (%s) stimmen mit dem Gesamtbetrag (%s) im Rahmen '
                    .'der Toleranz von %s überein. Abweichung: %s.',
                    $sum->format(),
                    $statement->totalAmount->format(),
                    $tolerance->format(),
                    $difference->format()
                ),
                ['differenceCent' => $difference->cents, 'provider' => $statement->provider]
            );
        } else {
            $findings[] = CheckFinding::blocker(
                CheckCode::HEATING_CHECKSUM_OUT_OF_TOLERANCE,
                sprintf(
                    'Die Einzelbeträge der Heizkostenabrechnung (%s) weichen um %s vom Gesamtbetrag (%s) ab. '
                    .'Die Toleranz von %s ist überschritten; eine Finalisierung ist erst nach Klärung möglich.',
                    $sum->format(),
                    $difference->format(),
                    $statement->totalAmount->format(),
                    $tolerance->format()
                ),
                ['differenceCent' => $difference->cents, 'provider' => $statement->provider]
            );
        }

        if ($statement->co2Status === Co2AllocationStatus::UNKNOWN) {
            $findings[] = CheckFinding::warning(
                CheckCode::HEATING_CO2_STATUS_UNKNOWN,
                'Es ist nicht erkennbar, ob die CO2-Kostenaufteilung in der Heizkostenabrechnung bereits enthalten '
                .'ist. Der Status ist zu klären.',
                ['provider' => $statement->provider]
            );
        }

        $allocationKey = null;

        if ($withinTolerance && $statement->amountsByParticipant !== []) {
            $allocationKey = DirectAssignmentKey::fromAmounts($statement->amountsByParticipant);
        }

        return new HeatingReconciliationResult(
            $statement->totalAmount,
            $sum,
            $difference,
            $tolerance,
            $withinTolerance,
            $findings,
            $allocationKey
        );
    }

    /**
     * Fall C: dezentrale Versorgung. Es entstehen keine Heizkostenzeilen.
     *
     * @return list<CheckFinding>
     */
    public function decentralizedSupply(): array
    {
        return [
            CheckFinding::info(
                CheckCode::HEATING_DECENTRALIZED_NO_COSTS,
                'Die Wärmeversorgung erfolgt dezentral; der Mieter bezieht die Energie direkt. Es werden keine '
                .'Heizkosten als Vermieterkosten angesetzt.'
            ),
        ];
    }
}
