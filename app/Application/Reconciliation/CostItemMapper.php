<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\Reconciliation\Dto\MappingOutcome;
use App\Application\Reconciliation\Dto\MissingRequirement;
use App\Application\Reconciliation\Dto\ProposedCostItem;
use App\Application\Reconciliation\Support\ExtractedFieldBag;
use App\Enums\ApportionmentStatus;
use App\Enums\CostItemSource;
use App\Enums\DocumentType;
use App\Enums\Paragraph35aType;
use App\Models\BillingRun;
use App\Models\CostCategory;
use App\Models\Document;
use Illuminate\Support\Carbon;

/**
 * Bruecke von den ausgelesenen Inhaltsdaten eines Belegs zu vorgeschlagenen
 * Kostenpositionen.
 *
 * VERBINDLICHE REGELN
 *
 *  1. Es entstehen ausschliesslich Vorschlaege. Nichts wird automatisch
 *     bestaetigt (Abschnitt 6.5, Schritt 6).
 *  2. Fehlende Pflichtangaben werden niemals geschaetzt. Sie erzeugen eine
 *     konkrete Pruefaufgabe (Grundsatz 5).
 *  3. Der Lohnanteil nach § 35a EStG wird nur uebernommen, wenn der Beleg ihn
 *     ausdruecklich beziffert. Materialkosten werden nie als Lohnanteil
 *     ausgegeben (Abschnitt 12.4).
 *  4. Ohne sicher bestimmbare Kategorie bleibt die Position pruefpflichtig.
 *     Der Kategorievorschlag ist keine Rechtsfreigabe.
 *  5. Eine Gutschrift oder ein Storno wird nicht automatisch verrechnet. Der
 *     erkannte Betrag bleibt unveraendert, die Position wird pruefpflichtig.
 */
final class CostItemMapper
{
    public function __construct(private readonly CategoryResolver $categories) {}

    /**
     * Belegartige Dokumente, die ueber das Schema "rechnung_bescheid"
     * ausgewertet werden.
     *
     * @return list<DocumentType>
     */
    public static function supportedTypes(): array
    {
        return [
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
            DocumentType::GUTSCHRIFT,
            DocumentType::STORNO,
        ];
    }

    public static function supports(Document $document): bool
    {
        $type = $document->getAttribute('document_type');

        return $type instanceof DocumentType && in_array($type, self::supportedTypes(), true);
    }

    public function map(BillingRun $billingRun, Document $document, ?ExtractedFieldBag $bag = null): MappingOutcome
    {
        $bag ??= ExtractedFieldBag::forDocument($document);

        $documentId = (string) $document->getKey();
        $sourceLabel = $this->sourceLabel($document);
        $supplier = $bag->text('aussteller', 190);
        $invoiceNumber = $bag->text('belegnummer', 80);
        $documentDate = $bag->date('belegdatum');
        $documentKind = $bag->text('belegart', 40);

        $proposals = [];
        $missing = [];

        if ($documentDate === null) {
            $missing[] = new MissingRequirement(
                'Belegdatum',
                sprintf(
                    'Aus %s ließ sich kein Belegdatum auslesen. Bitte tragen Sie das Datum nach. '
                    .'Ein Datum wird nicht geschätzt.',
                    $sourceLabel
                ),
                $documentId,
            );
        }

        $indexes = $bag->listIndexes('positionen');
        $hasPositions = false;

        foreach ($indexes as $index) {
            $prefix = sprintf('positionen[%d]', $index);
            $amount = $bag->integer($prefix.'.betrag_brutto_cent');

            if ($amount === null) {
                continue;
            }

            $hasPositions = true;

            $proposals[] = $this->buildProposal(
                $billingRun,
                $document,
                $bag,
                $prefix.'.',
                sprintf('%s#p%d', $documentId, $index),
                $bag->text($prefix.'.bezeichnung', 190),
                $amount,
                $supplier,
                $invoiceNumber,
                $documentDate,
                $documentKind,
                $missing,
            );
        }

        if (! $hasPositions) {
            $total = $bag->integer('gesamtbetrag_brutto_cent');

            if ($total === null) {
                $missing[] = new MissingRequirement(
                    'Betrag',
                    sprintf(
                        'Aus %s ließ sich kein Betrag auslesen. Ohne Betrag entsteht keine Kostenposition. '
                        .'Bitte erfassen Sie die Position manuell oder laden Sie die Unterlage erneut zur '
                        .'Auswertung hoch.',
                        $sourceLabel
                    ),
                    $documentId,
                );
            } else {
                $proposals[] = $this->buildProposal(
                    $billingRun,
                    $document,
                    $bag,
                    '',
                    sprintf('%s#gesamt', $documentId),
                    $bag->text('vorgeschlagene_kostenart', 190),
                    $total,
                    $supplier,
                    $invoiceNumber,
                    $documentDate,
                    $documentKind,
                    $missing,
                );
            }
        }

        return new MappingOutcome($proposals, $missing);
    }

    /**
     * @param  list<MissingRequirement>  $missing
     */
    private function buildProposal(
        BillingRun $billingRun,
        Document $document,
        ExtractedFieldBag $bag,
        string $prefix,
        string $proposalKey,
        ?string $label,
        int $amountCent,
        ?string $supplier,
        ?string $invoiceNumber,
        ?Carbon $documentDate,
        ?string $documentKind,
        array &$missing,
    ): ProposedCostItem {
        $documentId = (string) $document->getKey();
        $sourceLabel = $this->sourceLabel($document);

        $amountPath = $prefix === ''
            ? 'gesamtbetrag_brutto_cent'
            : $prefix.'betrag_brutto_cent';

        $description = $label;

        if ($description === null) {
            $description = sprintf('Position ohne Bezeichnung aus %s', $sourceLabel);

            $missing[] = new MissingRequirement(
                'Bezeichnung',
                sprintf(
                    'Für eine Position aus %s ließ sich keine Bezeichnung auslesen. '
                    .'Bitte ergänzen Sie die Bezeichnung, damit die Position der richtigen Kostenart '
                    .'zugeordnet werden kann.',
                    $sourceLabel
                ),
                $documentId,
            );
        }

        $periodStart = $bag->date($prefix.'leistungszeitraum_von') ?? $bag->date('leistungszeitraum_von');
        $periodEnd = $bag->date($prefix.'leistungszeitraum_bis') ?? $bag->date('leistungszeitraum_bis');

        if ($periodStart === null || $periodEnd === null) {
            $missing[] = new MissingRequirement(
                'Leistungszeitraum',
                sprintf(
                    'Für "%s" aus %s ließ sich kein vollständiger Leistungszeitraum auslesen. '
                    .'Der Zeitraum entscheidet über die Zuordnung zum Abrechnungszeitraum und wird nicht '
                    .'geschätzt. Bitte ergänzen Sie ihn.',
                    $description,
                    $sourceLabel
                ),
                $documentId,
            );
        }

        $categoryCode = $this->categories->proposeCode($label)
            ?? $this->categories->proposeCode($bag->text('vorgeschlagene_kostenart', 190));

        $category = $this->categories->byCode($billingRun, $categoryCode);

        $laborShare = $bag->integer($prefix.'lohnanteil_cent');
        $paragraph35a = Paragraph35aType::NONE;

        if ($laborShare !== null && $laborShare > 0 && $category instanceof CostCategory) {
            $type = $category->getAttribute('paragraph_35a_type');
            $paragraph35a = $type instanceof Paragraph35aType ? $type : Paragraph35aType::NONE;
        }

        $apportionment = ApportionmentStatus::PRUEFPFLICHTIG;
        $excluded = false;

        if ($category instanceof CostCategory) {
            $status = $category->getAttribute('apportionment_status');
            $apportionment = $status instanceof ApportionmentStatus ? $status : ApportionmentStatus::PRUEFPFLICHTIG;
            $excluded = $category->getAttribute('excluded_from_apportionment_by_default') === true;
        }

        // Eine Gutschrift oder ein Storno wird nicht automatisch verrechnet.
        if ($documentKind === 'GUTSCHRIFT' || $documentKind === 'STORNO') {
            $apportionment = ApportionmentStatus::PRUEFPFLICHTIG;
        }

        $isHeating = $category instanceof CostCategory && $category->getAttribute('is_heating_related') === true;
        $isWarmWater = $category instanceof CostCategory && $category->getAttribute('is_warm_water_related') === true;

        return new ProposedCostItem(
            $proposalKey,
            mb_substr($description, 0, 190),
            $amountCent,
            $documentId,
            $supplier,
            $invoiceNumber,
            $documentDate,
            $periodStart,
            $periodEnd,
            $categoryCode,
            $apportionment,
            $excluded,
            $paragraph35a,
            $laborShare !== null && $laborShare > 0 ? $laborShare : null,
            $bag->lowestConfidence($amountPath, $prefix.'bezeichnung', 'belegdatum'),
            $bag->firstPage($amountPath, $prefix.'bezeichnung', 'belegdatum'),
            $bag->firstExcerpt($amountPath, $prefix.'bezeichnung'),
            $isHeating,
            $isWarmWater,
            null,
            CostItemSource::KI_EXTRAKTION,
            $sourceLabel,
        );
    }

    private function sourceLabel(Document $document): string
    {
        $label = $document->getAttribute('source_label');

        return is_string($label) && $label !== '' ? $label : 'der Unterlage';
    }
}
