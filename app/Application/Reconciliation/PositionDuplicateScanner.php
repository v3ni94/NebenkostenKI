<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\Reconciliation\Dto\DuplicateFinding;
use App\Application\Reconciliation\Support\ExtractedFieldBag;
use App\Domain\Calculation\Check\InvoiceReference;
use App\Domain\Money\Money;
use App\Enums\DocumentRelationType;
use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\DocumentRelation;
use Illuminate\Support\Carbon;

/**
 * Dublettenerkennung auf Positionsebene (Abschnitt 12.5).
 *
 * Erkannt werden:
 *
 *  - gleiche Rechnungsnummer beim gleichen Lieferanten,
 *  - gleicher Betrag mit gleichem Belegdatum beim gleichen Lieferanten,
 *  - gleicher HMAC-Fingerabdruck des Quelldokuments,
 *  - Rechnung und zugehoerige Gutschrift.
 *
 * Ein Treffer wird niemals still addiert und niemals still entfernt. Er wird
 * als DocumentRelation und als Pruefaufgabe gefuehrt; der Nutzer entscheidet.
 *
 * Die Paarbildung folgt der Domainlogik in
 * App\Domain\Calculation\Check\DuplicateCostDetector. Hier steht nur die
 * Persistenz und der Bezug auf die Dokumente.
 */
final class PositionDuplicateScanner
{
    public function __construct(private readonly IssueRecorder $issues) {}

    /**
     * @return list<DuplicateFinding>
     */
    public function scan(BillingRun $billingRun): array
    {
        $items = CostItem::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        $fingerprints = $this->fingerprints($billingRun);
        $creditNoteTargets = $this->creditNoteTargets($billingRun);

        $findings = [];
        $count = count($items);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $items[$i];
                $right = $items[$j];

                $leftDocument = $this->documentId($left);
                $rightDocument = $this->documentId($right);

                // Mehrere Positionen desselben Belegs sind keine Dublette.
                if ($leftDocument !== null && $leftDocument === $rightDocument) {
                    continue;
                }

                if ($this->isCreditNotePair($left, $right, $creditNoteTargets)) {
                    $findings[] = new DuplicateFinding(
                        (string) $left->getKey(),
                        (string) $right->getKey(),
                        'Rechnung und zugehörige Gutschrift beziehungsweise Storno.',
                        true,
                        $leftDocument,
                        $rightDocument,
                    );

                    continue;
                }

                $reason = $this->duplicateReason($left, $right, $fingerprints);

                if ($reason === null) {
                    continue;
                }

                $findings[] = new DuplicateFinding(
                    (string) $left->getKey(),
                    (string) $right->getKey(),
                    $reason,
                    false,
                    $leftDocument,
                    $rightDocument,
                );
            }
        }

        return $findings;
    }

    /**
     * Schreibt die Treffer als Beziehung und als Pruefaufgabe.
     *
     * @param  list<DuplicateFinding>  $findings
     */
    public function persist(BillingRun $billingRun, array $findings): void
    {
        foreach ($findings as $finding) {
            $this->markCostItem($finding);
            $this->writeRelation($billingRun, $finding);

            if ($finding->isCreditNotePair) {
                $this->issues->hint(
                    $billingRun,
                    RuleCode::CREDIT_NOTE_PAIR,
                    'Rechnung und Gutschrift erkannt',
                    'Zu einer Rechnung liegt eine Gutschrift beziehungsweise ein Storno vor. Die Beträge werden '
                    .'nicht automatisch verrechnet. Bitte entscheiden Sie, welcher Betrag in die Abrechnung '
                    .'eingeht.',
                    CostItem::class,
                    $finding->costItemId,
                );

                continue;
            }

            $this->issues->warning(
                $billingRun,
                RuleCode::DUPLICATE_POSITION,
                'Mögliche Dublette',
                sprintf(
                    'Zwei Kostenpositionen ähneln sich. %s Die Beträge werden nicht addiert, solange Sie nicht '
                    .'entschieden haben, welche Position gilt.',
                    $finding->reason
                ),
                CostItem::class,
                $finding->costItemId,
            );
        }
    }

    private function markCostItem(DuplicateFinding $finding): void
    {
        if ($finding->isCreditNotePair) {
            return;
        }

        $item = CostItem::query()->whereKey($finding->costItemId)->first();

        if (! $item instanceof CostItem) {
            return;
        }

        $item->forceFill([
            'duplicate_of_cost_item_id' => $finding->duplicateOfCostItemId,
        ])->save();
    }

    private function writeRelation(BillingRun $billingRun, DuplicateFinding $finding): void
    {
        if ($finding->documentId === null || $finding->duplicateOfDocumentId === null) {
            return;
        }

        if ($finding->documentId === $finding->duplicateOfDocumentId) {
            return;
        }

        $type = $finding->isCreditNotePair
            ? DocumentRelationType::GUTSCHRIFT_ZU_RECHNUNG
            : DocumentRelationType::DUBLETTE;

        $existing = DocumentRelation::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('from_document_id', $finding->documentId)
            ->where('to_document_id', $finding->duplicateOfDocumentId)
            ->where('relation_type', $type->value)
            ->exists();

        if ($existing) {
            return;
        }

        $relation = new DocumentRelation;

        $relation->fill([
            'organization_id' => $billingRun->getAttribute('organization_id'),
            'billing_run_id' => $billingRun->getKey(),
            'from_document_id' => $finding->documentId,
            'to_document_id' => $finding->duplicateOfDocumentId,
            'relation_type' => $type,
            'note' => mb_substr($finding->reason, 0, 190),
        ]);

        $relation->save();
    }

    /**
     * @param  array<string, string>  $fingerprints
     */
    private function duplicateReason(CostItem $left, CostItem $right, array $fingerprints): ?string
    {
        $reference = $this->reference($left, $fingerprints);
        $other = $this->reference($right, $fingerprints);

        if ($reference->fingerprint !== null && $reference->fingerprint === $other->fingerprint) {
            return 'Beide Positionen verweisen auf dieselbe Unterlage, erkennbar am Fingerabdruck des Belegs.';
        }

        if (
            $reference->invoiceNumber !== null
            && $reference->invoiceNumber === $other->invoiceNumber
        ) {
            return 'Gleiche Rechnungsnummer.';
        }

        if (
            $reference->invoiceDate !== null
            && $reference->invoiceDate === $other->invoiceDate
            && $reference->amount->equals($other->amount)
        ) {
            return 'Gleicher Betrag mit gleichem Belegdatum.';
        }

        return null;
    }

    /**
     * @param  array<string, string>  $fingerprints
     */
    private function reference(CostItem $item, array $fingerprints): InvoiceReference
    {
        $documentId = $this->documentId($item);
        $date = $item->getAttribute('document_date');
        $supplier = $item->getAttribute('supplier_name');
        $number = $item->getAttribute('invoice_number');

        return new InvoiceReference(
            (string) $item->getKey(),
            (string) $item->getAttribute('description'),
            Money::fromCents((int) $item->getAttribute('amount_cent')),
            is_string($supplier) ? $supplier : null,
            is_string($number) && $number !== '' ? $number : null,
            $date instanceof Carbon ? $date->toDateString() : null,
            $documentId === null ? null : ($fingerprints[$documentId] ?? null),
        );
    }

    /**
     * @return array<string, string>
     */
    private function fingerprints(BillingRun $billingRun): array
    {
        $map = [];

        $documents = Document::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->get();

        foreach ($documents as $document) {
            $fingerprint = $document->getAttribute('fingerprint_hmac');

            if (is_string($fingerprint) && $fingerprint !== '') {
                $map[(string) $document->getKey()] = $fingerprint;
            }
        }

        return $map;
    }

    /**
     * Zuordnung Dokument einer Gutschrift oder eines Stornos zu der
     * Belegnummer, auf die es sich bezieht.
     *
     * @return array<string, string>
     */
    private function creditNoteTargets(BillingRun $billingRun): array
    {
        $targets = [];

        $documents = Document::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->whereIn('document_type', [DocumentType::GUTSCHRIFT->value, DocumentType::STORNO->value])
            ->get();

        foreach ($documents as $document) {
            $bag = ExtractedFieldBag::forDocument($document);
            $reference = $bag->text('bezug_auf_belegnummer', 80);

            if ($reference !== null) {
                $targets[(string) $document->getKey()] = $reference;
            }
        }

        return $targets;
    }

    /**
     * Eine Gutschrift und die Rechnung, auf die sie sich bezieht, sind keine
     * Dublette, sondern ein zusammengehoerendes Paar.
     *
     * @param  array<string, string>  $creditNoteTargets
     */
    private function isCreditNotePair(CostItem $left, CostItem $right, array $creditNoteTargets): bool
    {
        return $this->referencesInvoiceOf($left, $right, $creditNoteTargets)
            || $this->referencesInvoiceOf($right, $left, $creditNoteTargets);
    }

    /**
     * @param  array<string, string>  $creditNoteTargets
     */
    private function referencesInvoiceOf(CostItem $creditNote, CostItem $invoice, array $creditNoteTargets): bool
    {
        $documentId = $this->documentId($creditNote);

        if ($documentId === null || ! array_key_exists($documentId, $creditNoteTargets)) {
            return false;
        }

        $number = $invoice->getAttribute('invoice_number');

        return is_string($number) && $number !== '' && $number === $creditNoteTargets[$documentId];
    }

    private function documentId(CostItem $item): ?string
    {
        $value = $item->getAttribute('document_id');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
