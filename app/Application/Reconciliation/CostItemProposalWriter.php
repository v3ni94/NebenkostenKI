<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\Reconciliation\Dto\MappingOutcome;
use App\Application\Reconciliation\Dto\ProposedCostItem;
use App\Enums\ApportionmentStatus;
use App\Enums\CostItemSource;
use App\Enums\CostItemStatus;
use App\Enums\ValidationSeverity;
use App\Models\BillingRun;
use App\Models\CostCategory;
use App\Models\CostItem;
use App\Models\Document;
use Illuminate\Support\Carbon;

/**
 * Schreibt Vorschlaege als Kostenpositionen.
 *
 * VERBINDLICH
 *
 *  1. Jede erzeugte Position hat den Status VORGESCHLAGEN. Es wird nie
 *     automatisch bestaetigt.
 *  2. Positionen in nicht umlagefaehigen Kategorien werden von der Umlage
 *     ausgeschlossen (excluded_from_apportionment). Eine Aufnahme erfordert
 *     eine Begruendung des Nutzers und ist keine juristische Freigabe.
 *  3. Fehlende Pflichtangaben erzeugen eine Pruefaufgabe, niemals einen
 *     geschaetzten Wert.
 *  4. Ein erneuter Lauf ersetzt nur unangetastete Vorschlaege. Bestaetigte,
 *     verworfene und manuell erfasste Positionen bleiben erhalten.
 */
final class CostItemProposalWriter
{
    public function __construct(
        private readonly CategoryResolver $categories,
        private readonly IssueRecorder $issues,
    ) {}

    /**
     * Entfernt die noch nicht entschiedenen maschinellen Vorschlaege, damit ein
     * erneuter Lauf keine Dubletten erzeugt.
     */
    public function clearUndecidedProposals(BillingRun $billingRun): void
    {
        CostItem::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', CostItemStatus::VORGESCHLAGEN->value)
            ->whereNull('confirmed_at')
            ->where('source', CostItemSource::KI_EXTRAKTION->value)
            ->delete();
    }

    /**
     * @return list<CostItem>
     */
    public function write(BillingRun $billingRun, MappingOutcome $outcome): array
    {
        $written = [];

        foreach ($outcome->proposals as $proposal) {
            $written[] = $this->writeProposal($billingRun, $proposal);
        }

        foreach ($outcome->missing as $requirement) {
            $this->issues->record(
                $billingRun,
                // Eine blockierende Luecke ist ein Fall unzureichender
                // Unterlagen nach Abschnitt 7.5.
                $requirement->blocksFinalization ? RuleCode::INSUFFICIENT_DOCUMENTS : RuleCode::MISSING_MANDATORY,
                $requirement->blocksFinalization ? ValidationSeverity::BLOCKER : ValidationSeverity::WARNUNG,
                sprintf(
                    '%s: %s',
                    $requirement->blocksFinalization ? 'Unterlage fehlt' : 'Angabe fehlt',
                    $requirement->fieldLabel
                ),
                $requirement->explanation,
                $requirement->documentId === null ? null : Document::class,
                $requirement->documentId,
                $requirement->blocksFinalization,
            );
        }

        return $written;
    }

    public function writeProposal(BillingRun $billingRun, ProposedCostItem $proposal): CostItem
    {
        $category = $this->categories->byCode($billingRun, $proposal->categoryCode);

        $apportionment = $proposal->apportionmentStatus;
        $excluded = $proposal->excludedFromApportionment;

        if ($category instanceof CostCategory) {
            $status = $category->getAttribute('apportionment_status');

            if ($status instanceof ApportionmentStatus) {
                // Die Kategorie kann den Status nur verschaerfen. Ein bereits
                // pruefpflichtiger Vorschlag, etwa eine Gutschrift, wird nie
                // durch die Kategorie freigegeben.
                $apportionment = $this->stricter($apportionment, $status);
            }

            if ($category->getAttribute('excluded_from_apportionment_by_default') === true) {
                $excluded = true;
            }
        }

        // Nicht umlagefaehige Positionen sind standardmaessig ausgeschlossen
        // (Abschnitt 12.2).
        if ($apportionment === ApportionmentStatus::NICHT_UMLAGEFAEHIG) {
            $excluded = true;
        }

        $item = new CostItem;

        $item->fill([
            'organization_id' => $billingRun->getAttribute('organization_id'),
            'billing_run_id' => $billingRun->getKey(),
            'cost_category_id' => $category?->getKey(),
            'document_id' => $proposal->documentId,
            'description' => mb_substr($proposal->description, 0, 190),
            'supplier_name' => $proposal->supplierName,
            'invoice_number' => $proposal->invoiceNumber,
            'amount_cent' => $proposal->amountCent,
            'document_date' => $proposal->documentDate,
            'service_period_start' => $proposal->servicePeriodStart,
            'service_period_end' => $proposal->servicePeriodEnd,
            'source' => $proposal->source,
            // Kein Vorschlag ist bestaetigt.
            'status' => CostItemStatus::VORGESCHLAGEN,
            'apportionment_status' => $apportionment,
            'excluded_from_apportionment' => $excluded,
            'labor_share_cent' => $proposal->laborShareCent,
            'paragraph_35a_type' => $proposal->paragraph35aType,
            'is_heating_cost' => $proposal->isHeatingCost,
            'is_warm_water_cost' => $proposal->isWarmWaterCost,
            'direct_unit_id' => $proposal->directUnitId,
            'confidence' => $proposal->confidence,
            'source_page' => $proposal->sourcePage,
        ]);

        $item->save();

        $this->recordPositionIssues($billingRun, $item, $proposal);

        return $item;
    }

    /**
     * Strengerer der beiden Umlagestatus. Reihenfolge: nicht umlagefaehig,
     * pruefpflichtig, umlagefaehig.
     */
    private function stricter(ApportionmentStatus $left, ApportionmentStatus $right): ApportionmentStatus
    {
        $rank = static fn (ApportionmentStatus $status): int => match ($status) {
            ApportionmentStatus::NICHT_UMLAGEFAEHIG => 2,
            ApportionmentStatus::PRUEFPFLICHTIG => 1,
            ApportionmentStatus::UMLAGEFAEHIG => 0,
        };

        return $rank($left) >= $rank($right) ? $left : $right;
    }

    private function recordPositionIssues(BillingRun $billingRun, CostItem $item, ProposedCostItem $proposal): void
    {
        if ($item->getAttribute('apportionment_status') === ApportionmentStatus::NICHT_UMLAGEFAEHIG) {
            $this->issues->warning(
                $billingRun,
                RuleCode::NOT_ALLOCABLE_CATEGORY,
                sprintf('Nicht umlagefähige Kostenart: %s', $item->getAttribute('description')),
                'Diese Kostenart wird nach der vorgeschlagenen Einordnung regelmäßig nicht auf Wohnraummieter '
                .'umgelegt. Die Position ist deshalb von der Umlage ausgeschlossen. Das ist eine allgemeine '
                .'Information und keine Rechtsberatung im Einzelfall.',
                CostItem::class,
                (string) $item->getKey(),
            );
        }

        $start = $proposal->servicePeriodStart;
        $end = $proposal->servicePeriodEnd;
        $runStart = $billingRun->getAttribute('period_start');
        $runEnd = $billingRun->getAttribute('period_end');

        if (! $runStart instanceof Carbon || ! $runEnd instanceof Carbon) {
            return;
        }

        $outside = ($start instanceof Carbon && ($start->lt($runStart) || $start->gt($runEnd)))
            || ($end instanceof Carbon && ($end->lt($runStart) || $end->gt($runEnd)));

        if (! $outside) {
            return;
        }

        $this->issues->warning(
            $billingRun,
            RuleCode::SERVICE_PERIOD_OUTSIDE,
            sprintf('Leistungszeitraum außerhalb des Abrechnungszeitraums: %s', $item->getAttribute('description')),
            sprintf(
                'Der Leistungszeitraum dieser Position (%s bis %s) liegt ganz oder teilweise außerhalb des '
                .'Abrechnungszeitraums %s bis %s. Bitte prüfen Sie die zeitliche Abgrenzung und entscheiden Sie, '
                .'welcher Anteil in diese Abrechnung gehört.',
                $start?->format('d.m.Y') ?? 'unbekannt',
                $end?->format('d.m.Y') ?? 'unbekannt',
                $runStart->format('d.m.Y'),
                $runEnd->format('d.m.Y')
            ),
            CostItem::class,
            (string) $item->getKey(),
        );
    }
}
