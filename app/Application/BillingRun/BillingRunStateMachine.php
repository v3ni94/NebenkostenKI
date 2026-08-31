<?php

declare(strict_types=1);

namespace App\Application\BillingRun;

use App\Application\Account\AuditRecorder;
use App\Enums\BillingRunStatus;
use App\Models\BillingRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Statusmaschine des Abrechnungslaufs.
 *
 * VERBINDLICHE REGELN (Masterprompt 11.5, 12, ARCHITECTURE.md Grundsatz 9)
 *
 *  1. Erlaubt ist ausschliesslich, was in der Uebergangstabelle steht. Jeder
 *     andere Wechsel wirft eine IllegalStatusTransitionException.
 *  2. Nach bestaetigter Zahlung gibt es keinen Rueckweg in einen
 *     Bearbeitungsstatus. Diese Regel wird zusaetzlich zur Tabelle geprueft,
 *     damit eine spaetere Tabellenerweiterung sie nicht versehentlich
 *     aushebeln kann.
 *  3. FINALIZED und CANCELLED sind endgueltig und besitzen keine ausgehenden
 *     Uebergaenge.
 *  4. FAILED besitzt genau einen ausgehenden Uebergang: einen erneuten Versuch
 *     der Finalisierung, und auch den nur bei bestaetigter Zahlung. Ohne diesen
 *     Weg haette ein Kunde bezahlt, ohne seine Abrechnungen erhalten zu koennen.
 *     Vor der Zahlung gibt es keinen Weg aus FAILED heraus, dort entsteht ein
 *     neuer Lauf.
 *  5. Eine Korrektur aendert einen bezahlten Stand niemals. Sie erzeugt eine
 *     neue Version, der alte Stand bleibt bestehen, siehe
 *     App\Application\BillingRun\RecordCorrection.
 *  6. Jeder Uebergang schreibt einen Revisionseintrag mit Akteur, Aktion,
 *     Entitaet, Zeitpunkt und gekuerzter IP.
 */
class BillingRunStateMachine
{
    public const AUDIT_ACTION = 'billing_run.status_changed';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Uebergangstabelle. Schluessel ist der Ausgangsstatus.
     *
     * @return array<string, list<BillingRunStatus>>
     */
    public static function transitions(): array
    {
        return [
            // Der Lauf ist angelegt, es sind noch keine Unterlagen da.
            BillingRunStatus::DRAFT->value => [
                BillingRunStatus::UPLOADING,
                BillingRunStatus::FAILED,
                BillingRunStatus::CANCELLED,
            ],

            // Unterlagen werden hochgeladen. Werden alle entfernt, geht es
            // zurueck in den Entwurf.
            BillingRunStatus::UPLOADING->value => [
                BillingRunStatus::DRAFT,
                BillingRunStatus::EXTRACTING,
                BillingRunStatus::FAILED,
                BillingRunStatus::CANCELLED,
            ],

            // Die Auswertung laeuft. Ohne Prueffaelle geht es direkt weiter.
            BillingRunStatus::EXTRACTING->value => [
                BillingRunStatus::REVIEW_REQUIRED,
                BillingRunStatus::READY_FOR_CALCULATION,
                BillingRunStatus::FAILED,
                BillingRunStatus::CANCELLED,
            ],

            BillingRunStatus::REVIEW_REQUIRED->value => [
                BillingRunStatus::UPLOADING,
                BillingRunStatus::READY_FOR_CALCULATION,
                BillingRunStatus::FAILED,
                BillingRunStatus::CANCELLED,
            ],

            BillingRunStatus::READY_FOR_CALCULATION->value => [
                BillingRunStatus::UPLOADING,
                BillingRunStatus::REVIEW_REQUIRED,
                BillingRunStatus::CALCULATED,
                BillingRunStatus::FAILED,
                BillingRunStatus::CANCELLED,
            ],

            BillingRunStatus::CALCULATED->value => [
                BillingRunStatus::UPLOADING,
                BillingRunStatus::REVIEW_REQUIRED,
                BillingRunStatus::READY_FOR_CALCULATION,
                BillingRunStatus::PREVIEW_READY,
                BillingRunStatus::FAILED,
                BillingRunStatus::CANCELLED,
            ],

            // Die Vorschau mit Wasserzeichen liegt vor. Von hier fuehrt der Weg
            // in den Checkout oder zurueck in die Bearbeitung.
            BillingRunStatus::PREVIEW_READY->value => [
                BillingRunStatus::UPLOADING,
                BillingRunStatus::REVIEW_REQUIRED,
                BillingRunStatus::READY_FOR_CALCULATION,
                BillingRunStatus::CALCULATED,
                BillingRunStatus::CHECKOUT_PENDING,
                BillingRunStatus::FAILED,
                BillingRunStatus::CANCELLED,
            ],

            // Der Checkout ist eingeleitet. Freigeschaltet wird ausschliesslich
            // durch den signaturgeprueften Webhook, niemals durch den
            // Browser-Redirect (Masterprompt 15.1).
            BillingRunStatus::CHECKOUT_PENDING->value => [
                BillingRunStatus::PREVIEW_READY,
                BillingRunStatus::PAID,
                BillingRunStatus::FAILED,
                BillingRunStatus::CANCELLED,
            ],

            // Ab hier ist der Calculation Snapshot gesperrt.
            BillingRunStatus::PAID->value => [
                BillingRunStatus::FINALIZING,
                BillingRunStatus::FAILED,
            ],

            BillingRunStatus::FINALIZING->value => [
                BillingRunStatus::FINALIZED,
                BillingRunStatus::FAILED,
            ],

            // Endzustand. Korrekturen erzeugen eine neue Version.
            BillingRunStatus::FINALIZED->value => [],

            // Nur der erneute Finalisierungsversuch nach bestaetigter Zahlung.
            BillingRunStatus::FAILED->value => [
                BillingRunStatus::FINALIZING,
            ],

            BillingRunStatus::CANCELLED->value => [],
        ];
    }

    /**
     * Alle aus diesem Status erreichbaren Zielstatus.
     *
     * @return list<BillingRunStatus>
     */
    public static function allowedTargets(BillingRunStatus $from): array
    {
        return self::transitions()[$from->value] ?? [];
    }

    /**
     * Reine Statuspruefung ohne Beruecksichtigung des Zahlungszeitpunkts.
     */
    public static function isAllowed(BillingRunStatus $from, BillingRunStatus $to): bool
    {
        if ($from->isPaid() && $to->isEditable()) {
            return false;
        }

        return in_array($to, self::allowedTargets($from), true);
    }

    /**
     * Prueft einen konkreten Uebergang und wirft bei Verstoss.
     *
     * @throws IllegalStatusTransitionException
     */
    public function assertCanTransition(BillingRun $billingRun, BillingRunStatus $to): void
    {
        $from = $this->currentStatus($billingRun);

        if ($from === $to) {
            throw IllegalStatusTransitionException::forTransition($from, $to);
        }

        if ($from->isPaid() && $to->isEditable()) {
            throw IllegalStatusTransitionException::afterPayment($from, $to);
        }

        if (self::allowedTargets($from) === []) {
            throw IllegalStatusTransitionException::terminal($from, $to);
        }

        if (! in_array($to, self::allowedTargets($from), true)) {
            throw IllegalStatusTransitionException::forTransition($from, $to);
        }

        // Ein erneuter Finalisierungsversuch aus FAILED setzt eine bestaetigte
        // Zahlung voraus.
        if ($from === BillingRunStatus::FAILED && $billingRun->getAttribute('paid_at') === null) {
            throw IllegalStatusTransitionException::paymentMissing($from, $to);
        }

        if ($to === BillingRunStatus::FINALIZING
            && $from === BillingRunStatus::PAID
            && $billingRun->getAttribute('paid_at') === null) {
            throw IllegalStatusTransitionException::paymentMissing($from, $to);
        }
    }

    public function canTransition(BillingRun $billingRun, BillingRunStatus $to): bool
    {
        try {
            $this->assertCanTransition($billingRun, $to);

            return true;
        } catch (IllegalStatusTransitionException) {
            return false;
        }
    }

    /**
     * Fuehrt den Uebergang aus und schreibt den Revisionseintrag.
     *
     * Statuswechsel und Revisionseintrag liegen in einer Transaktion. Ein Lauf
     * ohne zugehoerigen Revisionseintrag darf nicht entstehen.
     *
     * @param  array<string, scalar|null>  $metadata
     *
     * @throws IllegalStatusTransitionException
     */
    public function transitionTo(
        BillingRun $billingRun,
        BillingRunStatus $to,
        ?User $actor = null,
        array $metadata = [],
        ?string $reason = null,
    ): BillingRun {
        $this->assertCanTransition($billingRun, $to);

        $from = $this->currentStatus($billingRun);

        DB::transaction(function () use ($billingRun, $from, $to, $actor, $metadata, $reason): void {
            $aenderungen = ['status' => $to];

            // Fachliche Zeitstempel gehoeren zum Uebergang und werden hier
            // gesetzt, damit sie nicht an mehreren Stellen entstehen koennen.
            if ($to === BillingRunStatus::PAID && $billingRun->getAttribute('paid_at') === null) {
                $aenderungen['paid_at'] = now();
            }

            if ($to === BillingRunStatus::FINALIZED) {
                $aenderungen['finalized_at'] = now();
            }

            if ($to === BillingRunStatus::CANCELLED) {
                $aenderungen['cancelled_at'] = now();
            }

            $billingRun->forceFill($aenderungen)->save();

            $organizationId = $billingRun->getAttribute('organization_id');

            $this->audit->record(
                action: self::AUDIT_ACTION,
                subject: $billingRun,
                actor: $actor,
                organization: is_string($organizationId) ? $organizationId : null,
                metadata: array_merge($metadata, [
                    'von' => $from->value,
                    'nach' => $to->value,
                ]),
                reason: $reason,
            );
        });

        return $billingRun;
    }

    private function currentStatus(BillingRun $billingRun): BillingRunStatus
    {
        $status = $billingRun->getAttribute('status');

        return $status instanceof BillingRunStatus ? $status : BillingRunStatus::DRAFT;
    }
}
