<?php

declare(strict_types=1);

namespace App\Application\Heating;

use App\Application\Reconciliation\Support\ExtractedFieldBag;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Models\Document;

/**
 * Erkennt konkurrierende Heizkostenquellen zur manuellen Erfassung
 * (Abschnitt 7.4).
 *
 * Liegen fuer dieselbe Einheit und denselben Zeitraum sowohl manuell erfasste
 * Betraege als auch Betraege aus einer externen Abrechnung oder einer
 * WEG-Summenposition vor, wird NICHT addiert. Es entsteht eine Pruefaufgabe,
 * und der Anwender entscheidet, welche Quelle gilt.
 *
 * Diese Klasse stellt nur fest, ob eine konkurrierende Quelle vorhanden ist.
 * Sie entscheidet nichts und rechnet nichts.
 */
final class ManualHeatingConflictScanner
{
    /**
     * @return list<string>
     */
    public function conflictingSources(BillingRun $billingRun): array
    {
        $sources = [];

        foreach ($this->documents($billingRun) as $document) {
            $type = $document->getAttribute('document_type');

            if ($type === DocumentType::HEIZKOSTENABRECHNUNG) {
                $sources[] = sprintf('externe Heizkostenabrechnung aus %s', $this->label($document));

                continue;
            }

            if ($type !== DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL) {
                continue;
            }

            $bag = ExtractedFieldBag::forDocument($document);

            foreach (['heizkosten_anteil_einheit_cent', 'warmwasserkosten_anteil_einheit_cent'] as $path) {
                if ($bag->integer($path) === null) {
                    continue;
                }

                $sources[] = sprintf('Heizkostenposition der Hausgeldabrechnung aus %s', $this->label($document));

                break;
            }
        }

        return array_values(array_unique($sources));
    }

    public function hasConflict(BillingRun $billingRun): bool
    {
        return $this->conflictingSources($billingRun) !== [];
    }

    /**
     * @return list<Document>
     */
    private function documents(BillingRun $billingRun): array
    {
        $documents = Document::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('processing_status', DocumentProcessingStatus::ABGESCHLOSSEN->value)
            ->orderBy('sequence_number')
            ->get()
            ->all();

        return array_values($documents);
    }

    private function label(Document $document): string
    {
        $label = $document->getAttribute('source_label');

        return is_string($label) && $label !== '' ? $label : 'einer Unterlage';
    }
}
