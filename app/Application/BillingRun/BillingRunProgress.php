<?php

declare(strict_types=1);

namespace App\Application\BillingRun;

use App\Enums\BillingRunStatus;
use App\Models\BillingRun;
use App\Models\User;

/**
 * Fortschritt des Abrechnungslaufs im echten Ablauf.
 *
 * ZWECK
 *
 * Die Schritte des gefuehrten Ablaufs (Upload, Auswertung, Zuordnung,
 * Kostenpruefung, Berechnung, Vorschau) sollen den Status des Laufs
 * mitfuehren, ohne dass jede Aufrufstelle die Uebergangstabelle kennt. Dieser
 * Dienst uebersetzt den fachlichen Schritt in einen Statuswechsel und nutzt
 * dafuer ausschliesslich die BillingRunStateMachine. Die Uebergangstabelle
 * bleibt damit die einzige Wahrheit.
 *
 * VERBINDLICHE REGELN
 *
 *  1. VORWAERTS ODER NICHTS. Der Fortschritt schaltet niemals zurueck. Liegt
 *     der Lauf bereits auf dem Zielstatus oder weiter, ist der Aufruf
 *     wirkungslos. Das macht jeden Aufruf idempotent: ein zweiter Aufruf
 *     derselben Methode aendert nichts.
 *  2. KEIN RUECKFALL NACH DER ZAHLUNG. Ein bezahlter (PAID, FINALIZING,
 *     FINALIZED) oder abgeschlossener Lauf (CANCELLED, FAILED) bleibt
 *     unberuehrt. Ein erneut aufgerufener Wizard-Schritt kann einen bezahlten
 *     Lauf damit nicht in einen Bearbeitungsstatus zuruecksetzen
 *     (Masterprompt 11.5). Auch CHECKOUT_PENDING liegt bereits hinter der
 *     Vorschau und wird nicht mehr angetastet.
 *  3. KEINE AUSNAHMEN IM NORMALBETRIEB. Ein wirkungsloser Aufruf ist kein
 *     Fehler, sondern der erwartete Fall bei wiederholten Schritten. Es wird
 *     deshalb nichts geworfen; der Lauf wird unveraendert zurueckgegeben.
 *  4. KEIN SPRUNG UEBER ZUSTAENDE. Ist der Zielstatus vom aktuellen Stand aus
 *     nicht unmittelbar erreichbar, laeuft der Dienst die fachliche Kette
 *     Schritt fuer Schritt entlang, jeder Schritt einzeln durch die
 *     Statusmaschine geprueft und einzeln revisioniert.
 *
 * VORSCHAUGUELTIGKEIT (Punkt 3 des Auftrags)
 *
 * Eine abrechnungsrelevante Aenderung nach erzeugter Vorschau (neuer
 * Verteilerschluessel, geaenderte Vorauszahlung) macht die Vorschau ungueltig.
 * Das wird NICHT durch einen Rueckschritt im Status geloest, denn ein
 * Rueckschritt waere ein Rueckweg und wuerde Regel 1 und 2 aushebeln. Stattdessen
 * gilt der bereits vorhandene Weg: PreviewBuilder::invalidate setzt die
 * Vorschaudokumente auf UNGUELTIG und ReviewConfirmation::reset nimmt die
 * Pruefbestaetigung zurueck. Der Checkout verlangt beides (StartCheckout
 * prueft review_confirmed_at und responsibility_confirmed_at), ist also
 * gesperrt, obwohl der Status PREVIEW_READY bleibt. Der Nutzer erzeugt die
 * Vorschau neu, bestaetigt erneut und gelangt dann weiter.
 */
final class BillingRunProgress
{
    /**
     * Fachliche Kette des gefuehrten Ablaufs bis zur Vorschau. Die Reihenfolge
     * bestimmt, was "weiter" bedeutet.
     */
    private const array CHAIN = [
        BillingRunStatus::DRAFT,
        BillingRunStatus::UPLOADING,
        BillingRunStatus::EXTRACTING,
        BillingRunStatus::REVIEW_REQUIRED,
        BillingRunStatus::READY_FOR_CALCULATION,
        BillingRunStatus::CALCULATED,
        BillingRunStatus::PREVIEW_READY,
    ];

    public function __construct(private readonly BillingRunStateMachine $stateMachine) {}

    /**
     * Der erste Upload eines Laufs ist angenommen.
     */
    public function uploadBegonnen(BillingRun $billingRun, ?User $actor = null): BillingRun
    {
        return $this->advanceTo($billingRun, BillingRunStatus::UPLOADING, $actor);
    }

    /**
     * Die Auswertung der Unterlagen hat begonnen.
     */
    public function extraktionBegonnen(BillingRun $billingRun, ?User $actor = null): BillingRun
    {
        return $this->advanceTo($billingRun, BillingRunStatus::EXTRACTING, $actor);
    }

    /**
     * Die Zuordnung ist gelaufen und es sind Pruefaufgaben oder unbestaetigte
     * Vorschlaege offen.
     */
    public function pruefungErforderlich(BillingRun $billingRun, ?User $actor = null): BillingRun
    {
        return $this->advanceTo($billingRun, BillingRunStatus::REVIEW_REQUIRED, $actor);
    }

    /**
     * Die Kostenpruefung ist abgeschlossen.
     */
    public function bereitZurBerechnung(BillingRun $billingRun, ?User $actor = null): BillingRun
    {
        return $this->advanceTo($billingRun, BillingRunStatus::READY_FOR_CALCULATION, $actor);
    }

    /**
     * Ein Berechnungsstand liegt vor.
     */
    public function berechnet(BillingRun $billingRun, ?User $actor = null): BillingRun
    {
        return $this->advanceTo($billingRun, BillingRunStatus::CALCULATED, $actor);
    }

    /**
     * Die Vorschau mit Wasserzeichen ist erzeugt.
     */
    public function vorschauBereit(BillingRun $billingRun, ?User $actor = null): BillingRun
    {
        return $this->advanceTo($billingRun, BillingRunStatus::PREVIEW_READY, $actor);
    }

    /**
     * Wuerde der Aufruf den Lauf tatsaechlich weiterschalten?
     */
    public function wuerdeWeiterschalten(BillingRun $billingRun, BillingRunStatus $ziel): bool
    {
        return $this->missingSteps($billingRun, $ziel) !== [];
    }

    /**
     * Schaltet den Lauf bis zum Zielstatus weiter, niemals zurueck.
     */
    private function advanceTo(BillingRun $billingRun, BillingRunStatus $ziel, ?User $actor): BillingRun
    {
        $schritte = $this->missingSteps($billingRun, $ziel);

        if ($schritte === []) {
            return $billingRun;
        }

        foreach ($schritte as $schritt) {
            // Jeder Schritt einzeln durch die Statusmaschine: sie prueft den
            // Uebergang und schreibt den Revisionseintrag.
            if (! $this->stateMachine->canTransition($billingRun, $schritt)) {
                return $billingRun;
            }

            $this->stateMachine->transitionTo($billingRun, $schritt, $actor, ['fortschritt' => $ziel->value]);
        }

        return $billingRun;
    }

    /**
     * Noch fehlende Zwischenschritte bis zum Zielstatus. Leer, wenn der Aufruf
     * wirkungslos bleibt.
     *
     * @return list<BillingRunStatus>
     */
    private function missingSteps(BillingRun $billingRun, BillingRunStatus $ziel): array
    {
        $aktuell = $this->currentStatus($billingRun);

        // Bezahlt oder abgeschlossen: es gibt keinen Weg zurueck in die
        // Bearbeitung, auch nicht durch einen erneuten Wizard-Aufruf.
        if ($aktuell->isPaid() || $aktuell->isTerminal()) {
            return [];
        }

        $von = $this->rank($aktuell);
        $nach = $this->rank($ziel);

        // Der aktuelle Stand liegt ausserhalb der Kette (etwa
        // CHECKOUT_PENDING) und damit bereits hinter der Vorschau.
        if ($von === null || $nach === null || $von >= $nach) {
            return [];
        }

        // Erlaubt die Uebergangstabelle den direkten Weg, wird er genommen.
        if ($this->stateMachine->canTransition($billingRun, $ziel)) {
            return [$ziel];
        }

        $schritte = [];

        for ($i = $von + 1; $i <= $nach; $i++) {
            $schritte[] = self::CHAIN[$i];
        }

        return $schritte;
    }

    private function rank(BillingRunStatus $status): ?int
    {
        $index = array_search($status, self::CHAIN, true);

        return is_int($index) ? $index : null;
    }

    private function currentStatus(BillingRun $billingRun): BillingRunStatus
    {
        $status = $billingRun->getAttribute('status');

        return $status instanceof BillingRunStatus ? $status : BillingRunStatus::DRAFT;
    }
}
