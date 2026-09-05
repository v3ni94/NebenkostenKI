<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\Reconciliation\Dto\BillingModeSuggestion;
use App\Enums\BillingMode;
use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\Unit;

/**
 * Automatische Wegerkennung nach Abschnitt 5.3.
 *
 * Aus den erkannten Dokumentarten und der Zahl der Einheiten wird der
 * wahrscheinlich passende Weg vorgeschlagen. Der Vorschlag ist unverbindlich;
 * der Nutzer kann jederzeit wechseln.
 *
 * Ein Wechsel loescht keine strukturierten Extraktionsdaten. Das stellt
 * App\Application\Reconciliation\SwitchBillingMode sicher.
 */
final class BillingModeAdvisor
{
    public function suggest(BillingRun $billingRun): BillingModeSuggestion
    {
        $current = $billingRun->getAttribute('mode');
        $current = $current instanceof BillingMode ? $current : BillingMode::QUICK_CONDO;

        $types = $this->documentTypes($billingRun);
        $unitCount = $this->unitCount($billingRun);

        $reasons = [];
        $quickScore = 0;
        $fullScore = 0;

        if (in_array(DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, $types, true)) {
            $quickScore += 2;
            $reasons[] = 'Es liegt eine WEG-Einzelabrechnung vor. Sie weist die Kosten einer Eigentumswohnung aus.';
        }

        if (in_array(DocumentType::GRUNDSTEUERBESCHEID, $types, true)) {
            $quickScore++;
            $reasons[] = 'Es liegt ein Grundsteuerbescheid vor.';
        }

        $invoiceTypes = array_intersect(
            array_map(static fn (DocumentType $type): string => $type->value, $types),
            array_map(static fn (DocumentType $type): string => $type->value, [
                DocumentType::WASSER_ABWASSERBESCHEID,
                DocumentType::NIEDERSCHLAGSWASSERBESCHEID,
                DocumentType::STRASSENREINIGUNGSBESCHEID,
                DocumentType::MUELLGEBUEHRENBESCHEID,
                DocumentType::VERSICHERUNGSRECHNUNG,
                DocumentType::HAUSMEISTER_REINIGUNG_GARTEN,
                DocumentType::ALLGEMEINSTROM,
                DocumentType::AUFZUG_WARTUNG_SCHORNSTEIN,
                DocumentType::ENERGIE_BRENNSTOFFRECHNUNG,
                DocumentType::RECHNUNG,
            ])
        );

        if (count($invoiceTypes) >= 3) {
            $fullScore += 2;
            $reasons[] = sprintf(
                'Es liegen Einzelbelege aus %d verschiedenen Kostenbereichen vor. Das spricht für die '
                .'vollständige Objektabrechnung.',
                count($invoiceTypes)
            );
        }

        if (in_array(DocumentType::MIETER_EINHEITENLISTE, $types, true)) {
            $fullScore++;
            $reasons[] = 'Es liegt eine Mieter- und Einheitenliste vor.';
        }

        if ($unitCount > 1) {
            $fullScore += 2;
            $reasons[] = sprintf('Für das Objekt sind %d Einheiten erfasst.', $unitCount);
        } elseif ($unitCount === 1) {
            $quickScore++;
            $reasons[] = 'Für das Objekt ist genau eine Einheit erfasst.';
        }

        if ($reasons === []) {
            $reasons[] = 'Es liegen noch keine ausgewerteten Unterlagen vor. Der bisher gewählte Weg bleibt bestehen.';

            return new BillingModeSuggestion($current, $current, $reasons, false);
        }

        $suggested = $fullScore > $quickScore ? BillingMode::FULL_PROPERTY : BillingMode::QUICK_CONDO;

        return new BillingModeSuggestion(
            $suggested,
            $current,
            $reasons,
            abs($fullScore - $quickScore) >= 2,
        );
    }

    /**
     * @return list<DocumentType>
     */
    private function documentTypes(BillingRun $billingRun): array
    {
        $types = [];

        $documents = Document::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->get();

        foreach ($documents as $document) {
            $type = $document->getAttribute('document_type');

            if ($type instanceof DocumentType) {
                $types[$type->value] = $type;
            }
        }

        return array_values($types);
    }

    private function unitCount(BillingRun $billingRun): int
    {
        $propertyId = $billingRun->getAttribute('property_id');

        if (! is_string($propertyId) || $propertyId === '') {
            return 0;
        }

        return Unit::query()->where('property_id', $propertyId)->count();
    }
}
