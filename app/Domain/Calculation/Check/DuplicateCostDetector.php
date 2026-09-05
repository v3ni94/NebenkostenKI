<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Check;

use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckFinding;

/**
 * Dublettenprüfung von Kostenpositionen (Pflichtenheft Abschnitt 12.5).
 *
 * Erkannt werden:
 * - identischer Dateifingerabdruck,
 * - gleicher Lieferant mit gleicher Rechnungsnummer,
 * - gleicher Lieferant, gleicher Betrag und gleiches Datum.
 *
 * Gutschriften werden nicht als Dublette der zugehörigen Rechnung gemeldet;
 * sie sind ein eigener Vorgang mit umgekehrtem Vorzeichen.
 *
 * Der Detektor löscht oder verändert nichts. Er liefert Prüfergebnisse,
 * damit der Nutzer entscheidet.
 */
final class DuplicateCostDetector
{
    /**
     * @param  list<InvoiceReference>  $references
     * @return list<CheckFinding>
     */
    public function detect(array $references): array
    {
        $findings = [];
        $count = count($references);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $references[$i];
                $right = $references[$j];

                if ($this->isCreditNotePair($left, $right)) {
                    continue;
                }

                $reason = $this->duplicateReason($left, $right);

                if ($reason === null) {
                    continue;
                }

                $findings[] = CheckFinding::warning(
                    CheckCode::DUPLICATE_COST_SUSPECTED,
                    sprintf(
                        'Mögliche Dublette: "%s" (%s) und "%s" (%s). %s',
                        $left->label,
                        $left->amount->format(),
                        $right->label,
                        $right->amount->format(),
                        $reason
                    ),
                    [
                        'costItemKey' => $left->costItemKey,
                        'duplicateOf' => $right->costItemKey,
                        'reason' => $reason,
                    ]
                );
            }
        }

        return $findings;
    }

    private function isCreditNotePair(InvoiceReference $left, InvoiceReference $right): bool
    {
        if ($left->isCreditNote && $left->relatedInvoiceCostItemKey === $right->costItemKey) {
            return true;
        }

        return $right->isCreditNote && $right->relatedInvoiceCostItemKey === $left->costItemKey;
    }

    private function duplicateReason(InvoiceReference $left, InvoiceReference $right): ?string
    {
        if ($left->fingerprint !== null && $left->fingerprint === $right->fingerprint) {
            return 'Beide Positionen verweisen auf denselben Belegfingerabdruck.';
        }

        if (
            $left->supplier !== null
            && $left->supplier === $right->supplier
            && $left->invoiceNumber !== null
            && $left->invoiceNumber === $right->invoiceNumber
        ) {
            return 'Gleicher Lieferant und gleiche Rechnungsnummer.';
        }

        if (
            $left->supplier !== null
            && $left->supplier === $right->supplier
            && $left->invoiceDate !== null
            && $left->invoiceDate === $right->invoiceDate
            && $left->amount->equals($right->amount)
        ) {
            return 'Gleicher Lieferant, gleicher Betrag und gleiches Belegdatum.';
        }

        return null;
    }
}
