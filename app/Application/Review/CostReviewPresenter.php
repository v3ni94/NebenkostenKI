<?php

declare(strict_types=1);

namespace App\Application\Review;

use App\Application\Review\Dto\CostGroupView;
use App\Application\Review\Dto\CostPositionView;
use App\Application\Review\Dto\ReviewOverview;
use App\Application\Review\Dto\WarningBanner;
use App\Domain\Money\Money;
use App\Enums\ApportionmentStatus;
use App\Enums\CostItemSource;
use App\Enums\CostItemStatus;
use App\Enums\Paragraph35aType;
use App\Models\BillingRun;
use App\Models\CostCategory;
use App\Models\CostItem;
use App\Models\ExtractedField;
use Illuminate\Support\Carbon;

/**
 * Aufbereitung der Kostenpruefung (Schritt 6).
 *
 * Gruppierung nach Kategorie, Summe je Gruppe, aufklappbar auf die einzelnen
 * Quelldokumente.
 *
 * SAMMELBESTAETIGUNG: zugelassen ist ausschliesslich eine Position, die
 *
 *  - noch nicht entschieden ist,
 *  - eine zugeordnete Kategorie mit Status UMLAGEFAEHIG hat,
 *  - nicht von der Umlage ausgeschlossen ist,
 *  - keine moegliche Dublette ist,
 *  - einen Leistungszeitraum innerhalb des Abrechnungszeitraums hat und
 *  - die Konfidenzschwelle erreicht.
 *
 * Nicht umlagefaehige, pruefpflichtige und niedrigkonfidente Positionen sind
 * ausdruecklich ausgenommen und muessen einzeln behandelt werden.
 *
 * WEITER: erst moeglich, wenn jede Position bestaetigt oder verworfen ist.
 */
final class CostReviewPresenter
{
    public function threshold(): float
    {
        $value = config('ai.confidence_review_threshold');

        return is_numeric($value) ? (float) $value : 0.80;
    }

    public function overview(BillingRun $billingRun): ReviewOverview
    {
        $items = CostItem::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->with(['costCategory', 'document', 'directUnit'])
            ->orderBy('description')
            ->orderBy('id')
            ->get();

        $groups = [];
        $bulkConfirmable = [];
        $positionCount = 0;
        $open = 0;
        $confirmed = 0;
        $discarded = 0;
        $apportionableSum = 0;
        $excludedSum = 0;

        $notAllocableIds = [];
        $outsideIds = [];
        $duplicateIds = [];
        $lowConfidenceIds = [];

        foreach ($items as $item) {
            $view = $this->position($billingRun, $item);
            $positionCount++;

            $status = $item->getAttribute('status');

            if ($status === CostItemStatus::BESTAETIGT) {
                $confirmed++;
            } elseif ($status === CostItemStatus::VERWORFEN) {
                $discarded++;
            } else {
                $open++;
            }

            if ($view->bulkConfirmable) {
                $bulkConfirmable[] = $view->id;
            }

            if ($status !== CostItemStatus::VERWORFEN) {
                if ($view->excludedFromApportionment || $view->apportionmentStatus !== ApportionmentStatus::UMLAGEFAEHIG->value) {
                    $excludedSum += $view->amountCent;
                } else {
                    $apportionableSum += $view->amountCent;
                }
            }

            if ($view->apportionmentStatus === ApportionmentStatus::NICHT_UMLAGEFAEHIG->value) {
                $notAllocableIds[] = $view->id;
            }

            if ($view->servicePeriodOutside) {
                $outsideIds[] = $view->id;
            }

            if ($view->possibleDuplicate) {
                $duplicateIds[] = $view->id;
            }

            if ($view->confidenceVariant === 'warning') {
                $lowConfidenceIds[] = $view->id;
            }

            $key = $view->categoryId ?? 'ohne-kategorie';
            $groups[$key][] = $view;
        }

        $groupViews = [];

        foreach ($groups as $key => $positions) {
            $groupViews[] = $this->group((string) $key, $positions);
        }

        usort(
            $groupViews,
            static fn (CostGroupView $left, CostGroupView $right): int => strcmp($left->name, $right->name)
        );

        $blockedReason = $open > 0
            ? sprintf(
                'Es sind noch %d Kostenpositionen offen. Bitte bestätigen oder verwerfen Sie jede Position, '
                .'bevor Sie fortfahren.',
                $open
            )
            : null;

        return new ReviewOverview(
            $groupViews,
            $this->banners($notAllocableIds, $outsideIds, $duplicateIds, $lowConfidenceIds),
            $bulkConfirmable,
            $positionCount,
            $open,
            $confirmed,
            $discarded,
            $apportionableSum,
            Money::fromCents($apportionableSum)->format(),
            $excludedSum,
            Money::fromCents($excludedSum)->format(),
            $positionCount > 0 && $open === 0,
            $blockedReason,
        );
    }

    public function position(BillingRun $billingRun, CostItem $item): CostPositionView
    {
        $category = $item->costCategory;
        $status = $item->getAttribute('status');
        $status = $status instanceof CostItemStatus ? $status : CostItemStatus::VORGESCHLAGEN;
        $apportionment = $item->getAttribute('apportionment_status');
        $apportionment = $apportionment instanceof ApportionmentStatus
            ? $apportionment
            : ApportionmentStatus::PRUEFPFLICHTIG;

        $amount = (int) $item->getAttribute('amount_cent');
        $labor = $item->getAttribute('labor_share_cent');
        $confidence = $item->getAttribute('confidence');
        $confidence = is_string($confidence) ? $confidence : null;

        $outside = $this->servicePeriodOutside($billingRun, $item);
        $duplicate = $item->getAttribute('duplicate_of_cost_item_id');
        $duplicate = is_string($duplicate) && $duplicate !== '' ? $duplicate : null;

        $conflicts = [];

        if ($category === null) {
            $conflicts[] = 'Es ist noch keine Kostenart zugeordnet.';
        }

        if ($apportionment === ApportionmentStatus::NICHT_UMLAGEFAEHIG) {
            $conflicts[] = 'Die Kostenart ist als nicht umlagefähig eingeordnet.';
        }

        if ($apportionment === ApportionmentStatus::PRUEFPFLICHTIG) {
            $conflicts[] = 'Die Umlagefähigkeit ist ausdrücklich zu prüfen.';
        }

        if ($duplicate !== null) {
            $conflicts[] = 'Es besteht der Verdacht einer Dublette.';
        }

        if ($outside) {
            $conflicts[] = 'Der Leistungszeitraum liegt außerhalb des Abrechnungszeitraums.';
        }

        if ($confidence === null || (float) $confidence < $this->threshold()) {
            $conflicts[] = 'Der ausgelesene Wert liegt unter der Konfidenzschwelle und ist zu prüfen.';
        }

        $document = $item->document;
        $sourceLabel = $document?->getAttribute('source_label');
        $source = $item->getAttribute('source');

        if (! is_string($sourceLabel) || $sourceLabel === '') {
            $sourceLabel = $source === CostItemSource::MANUELL
                ? 'Manuell erfasst'
                : 'Quelle nicht mehr zuordenbar';
        }

        $unit = $item->directUnit;
        $unitLabel = $unit?->getAttribute('label');

        return new CostPositionView(
            (string) $item->getKey(),
            (string) $item->getAttribute('description'),
            $this->stringOrNull($item->getAttribute('supplier_name')),
            $this->stringOrNull($item->getAttribute('invoice_number')),
            $this->dateLabel($item->getAttribute('document_date')),
            $this->periodLabel($item),
            $amount,
            Money::fromCents($amount)->format(),
            $category instanceof CostCategory ? (string) $category->getKey() : null,
            $category instanceof CostCategory ? (string) $category->getAttribute('code') : null,
            $category instanceof CostCategory ? (string) $category->getAttribute('name') : null,
            $apportionment->value,
            $apportionment->label(),
            $item->getAttribute('excluded_from_apportionment') === true,
            is_int($labor) ? $labor : null,
            is_int($labor) ? Money::fromCents($labor)->format() : null,
            $this->paragraph35aLabel($item),
            $sourceLabel,
            is_int($item->getAttribute('source_page')) ? (int) $item->getAttribute('source_page') : null,
            $this->excerpt($item),
            $confidence,
            $this->confidenceLabel($confidence),
            $this->confidenceVariant($confidence),
            $duplicate !== null,
            $duplicate,
            $status->value,
            $status->label(),
            $status !== CostItemStatus::VORGESCHLAGEN,
            $outside,
            $this->isBulkConfirmable($item, $apportionment, $confidence, $duplicate, $outside, $category),
            $conflicts,
            is_string($unitLabel) && $unitLabel !== '' ? $unitLabel : null,
            $source === CostItemSource::MANUELL,
        );
    }

    /**
     * @param  list<CostPositionView>  $positions
     */
    private function group(string $key, array $positions): CostGroupView
    {
        $sum = 0;
        $openCount = 0;
        $duplicates = 0;
        $periodWarning = false;
        $notAllocable = false;
        $name = 'Ohne zugeordnete Kostenart';
        $code = null;
        $status = ApportionmentStatus::PRUEFPFLICHTIG;

        foreach ($positions as $position) {
            if ($position->status !== CostItemStatus::VERWORFEN->value) {
                $sum += $position->amountCent;
            }

            if (! $position->decided) {
                $openCount++;
            }

            if ($position->possibleDuplicate) {
                $duplicates++;
            }

            if ($position->servicePeriodOutside) {
                $periodWarning = true;
            }

            if ($position->apportionmentStatus === ApportionmentStatus::NICHT_UMLAGEFAEHIG->value) {
                $notAllocable = true;
            }

            if ($position->categoryName !== null) {
                $name = $position->categoryName;
                $code = $position->categoryCode;
                $status = ApportionmentStatus::tryFrom($position->apportionmentStatus) ?? $status;
            }
        }

        return new CostGroupView(
            $key,
            $name,
            $code,
            $sum,
            Money::fromCents($sum)->format(),
            $status->value,
            $status->label(),
            $positions,
            $openCount,
            $duplicates,
            $notAllocable,
            $periodWarning,
        );
    }

    /**
     * Pflicht-Warnhinweise. Die Formulierung ist allgemein und ausdruecklich
     * keine Rechtsberatung im Einzelfall.
     *
     * @param  list<string>  $notAllocable
     * @param  list<string>  $outside
     * @param  list<string>  $duplicates
     * @param  list<string>  $lowConfidence
     * @return list<WarningBanner>
     */
    private function banners(array $notAllocable, array $outside, array $duplicates, array $lowConfidence): array
    {
        $banners = [];

        if ($notAllocable !== []) {
            $banners[] = new WarningBanner(
                WarningBanner::KIND_NOT_ALLOCABLE,
                sprintf('%d Position(en) in einer nicht umlagefähigen Kostenart', count($notAllocable)),
                'Kosten für Verwaltung, Instandhaltung, Reparaturen, Rücklagen sowie Bank- und Rechtskosten sind '
                .'regelmäßig nicht auf Wohnraummieter umlegbar. Diese Angabe ist eine allgemeine Information und '
                .'keine Rechtsberatung im Einzelfall.',
                'warning',
                $notAllocable,
            );
        }

        if ($outside !== []) {
            $banners[] = new WarningBanner(
                WarningBanner::KIND_OUTSIDE_PERIOD,
                sprintf('%d Position(en) mit Leistungszeitraum außerhalb des Abrechnungszeitraums', count($outside)),
                'Leistungen außerhalb des Abrechnungszeitraums gehören regelmäßig nicht in diese Abrechnung oder '
                .'sind zeitlich abzugrenzen. Diese Angabe ist eine allgemeine Information und keine '
                .'Rechtsberatung im Einzelfall.',
                'warning',
                $outside,
            );
        }

        if ($duplicates !== []) {
            $banners[] = new WarningBanner(
                WarningBanner::KIND_DUPLICATE,
                sprintf('%d Position(en) mit Dublettenverdacht', count($duplicates)),
                'Diese Positionen ähneln sich in Belegnummer, Betrag oder Datum. Die Beträge werden nicht '
                .'addiert, solange Sie nicht entschieden haben, welche Position gilt.',
                'warning',
                $duplicates,
            );
        }

        if ($lowConfidence !== []) {
            $banners[] = new WarningBanner(
                WarningBanner::KIND_LOW_CONFIDENCE,
                sprintf('%d Position(en) mit geringer Erkennungssicherheit', count($lowConfidence)),
                'Bitte prüfen Sie diese Werte besonders sorgfältig. Eine Seitenansicht der Unterlagen ist nicht '
                .'möglich, weil die Originaldateien nach der Auswertung gelöscht wurden. Vergleichen Sie die '
                .'Werte mit Ihrer eigenen Kopie oder laden Sie die Unterlage erneut zur Auswertung hoch.',
                'info',
                $lowConfidence,
            );
        }

        return $banners;
    }

    private function isBulkConfirmable(
        CostItem $item,
        ApportionmentStatus $apportionment,
        ?string $confidence,
        ?string $duplicate,
        bool $outside,
        ?CostCategory $category,
    ): bool {
        if ($item->getAttribute('status') !== CostItemStatus::VORGESCHLAGEN) {
            return false;
        }

        if (! $category instanceof CostCategory) {
            return false;
        }

        if ($category->getAttribute('requires_manual_review') === true) {
            return false;
        }

        if ($category->getAttribute('requires_contract_basis') === true) {
            return false;
        }

        if ($apportionment !== ApportionmentStatus::UMLAGEFAEHIG) {
            return false;
        }

        if ($item->getAttribute('excluded_from_apportionment') === true) {
            return false;
        }

        if ($duplicate !== null || $outside) {
            return false;
        }

        return $confidence !== null && (float) $confidence >= $this->threshold();
    }

    public function servicePeriodOutside(BillingRun $billingRun, CostItem $item): bool
    {
        $runStart = $billingRun->getAttribute('period_start');
        $runEnd = $billingRun->getAttribute('period_end');

        if (! $runStart instanceof Carbon || ! $runEnd instanceof Carbon) {
            return false;
        }

        $start = $item->getAttribute('service_period_start');
        $end = $item->getAttribute('service_period_end');

        if ($start instanceof Carbon && ($start->lt($runStart) || $start->gt($runEnd))) {
            return true;
        }

        return $end instanceof Carbon && ($end->lt($runStart) || $end->gt($runEnd));
    }

    private function excerpt(CostItem $item): ?string
    {
        $documentId = $item->getAttribute('document_id');

        if (! is_string($documentId) || $documentId === '') {
            return null;
        }

        $page = $item->getAttribute('source_page');

        $query = ExtractedField::query()
            ->where('document_id', $documentId)
            ->whereNotNull('source_excerpt');

        if (is_int($page)) {
            $query->where('page_number', $page);
        }

        $value = $query->orderBy('schema_key')->value('source_excerpt');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function paragraph35aLabel(CostItem $item): string
    {
        $type = $item->getAttribute('paragraph_35a_type');

        return $type instanceof Paragraph35aType ? $type->label() : 'Nicht begünstigt';
    }

    private function confidenceLabel(?string $confidence): string
    {
        if ($confidence === null) {
            return 'Ohne Konfidenzangabe';
        }

        return sprintf('Erkennungssicherheit %d Prozent', (int) round(((float) $confidence) * 100));
    }

    private function confidenceVariant(?string $confidence): string
    {
        if ($confidence === null) {
            return 'warning';
        }

        $value = (float) $confidence;

        if ($value >= 0.95) {
            return 'success';
        }

        return $value >= $this->threshold() ? 'info' : 'warning';
    }

    private function dateLabel(mixed $value): ?string
    {
        return $value instanceof Carbon ? $value->format('d.m.Y') : null;
    }

    private function periodLabel(CostItem $item): ?string
    {
        $start = $item->getAttribute('service_period_start');
        $end = $item->getAttribute('service_period_end');

        if ($start instanceof Carbon && $end instanceof Carbon) {
            return sprintf('%s bis %s', $start->format('d.m.Y'), $end->format('d.m.Y'));
        }

        if ($start instanceof Carbon) {
            return sprintf('ab %s', $start->format('d.m.Y'));
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
