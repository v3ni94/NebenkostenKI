<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\Reconciliation\Dto\ExcludedPositionRow;
use App\Application\Reconciliation\Dto\MappingOutcome;
use App\Application\Reconciliation\Dto\MissingRequirement;
use App\Application\Reconciliation\Dto\ProposedCostItem;
use App\Application\Reconciliation\Support\ExtractedFieldBag;
use App\Domain\Calculation\Weg\HausgeldCostExtractor;
use App\Domain\Calculation\Weg\HausgeldPositionInput;
use App\Domain\Calculation\Weg\HausgeldPositionKind;
use App\Domain\Calculation\Weg\HausgeldStatementInput;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Enums\ApportionmentStatus;
use App\Enums\CostItemSource;
use App\Enums\DocumentType;
use App\Enums\Paragraph35aType;
use App\Models\BillingRun;
use App\Models\CostCategory;
use App\Models\Document;
use Illuminate\Support\Carbon;

/**
 * Uebernahme einer WEG-Einzelabrechnung in vorgeschlagene Kostenpositionen
 * (Abschnitt 7.1 und 7.2).
 *
 * Die fachliche Ausschlusslogik liegt bewusst in der framework-freien Domain
 * (App\Domain\Calculation\Weg\HausgeldCostExtractor). Diese Klasse baut nur
 * die Eingabe aus den ausgelesenen Inhaltsdaten und uebersetzt das Ergebnis in
 * Vorschlaege und getrennt ausgewiesene Ausschlusszeilen.
 *
 * VERBINDLICH
 *
 *  1. Uebernommen wird ausschliesslich der auf die konkrete Einheit
 *     entfallende Anteil je Kostenart.
 *  2. Die Positionen aus Abschnitt 7.2 werden verbindlich ausgeschlossen und
 *     getrennt ausgewiesen.
 *  3. Die Kennzeichnung "umlagefaehig" durch den Verwalter ist ein Vorschlag.
 *     Sie fuehrt weder zur Bestaetigung noch zum Umlagestatus UMLAGEFAEHIG.
 *     Maßgeblich sind Mietvertrag, BetrKV-Kategorie und Detailbelege.
 *  4. Liegt eine externe Heizkostenabrechnung vor, wird die Heizkostenposition
 *     der WEG-Abrechnung nicht zusaetzlich angesetzt (Abschnitt 7.4).
 *  5. Fehlt die Kostenaufschluesselung, entsteht keine scheinbar vollstaendige
 *     Abrechnung (Abschnitt 7.5).
 */
final class HausgeldReconciler
{
    /**
     * Aggregatfelder der Abrechnung, die nach Abschnitt 7.2 immer getrennt
     * auszuweisen und niemals zu uebernehmen sind.
     *
     * @var array<string, array{0: HausgeldPositionKind, 1: string}>
     */
    private const array AGGREGATE_EXCLUSIONS = [
        'hausgeldvorauszahlungen_cent' => [HausgeldPositionKind::HOUSE_MONEY_PREPAYMENT, 'Hausgeldvorauszahlungen'],
        'abrechnungsspitze_cent' => [HausgeldPositionKind::SETTLEMENT_BALANCE, 'Abrechnungsspitze gegenüber der WEG'],
        'ruecklagenzufuehrung_cent' => [HausgeldPositionKind::RESERVE_CONTRIBUTION, 'Zuführung zur Erhaltungsrücklage'],
        'ruecklagenentnahme_cent' => [HausgeldPositionKind::RESERVE_WITHDRAWAL, 'Entnahme aus der Erhaltungsrücklage'],
        'verwalterverguetung_cent' => [HausgeldPositionKind::ADMINISTRATION_COST, 'Verwaltervergütung'],
        'bank_finanzierungskosten_cent' => [HausgeldPositionKind::BANK_AND_FINANCING_COST, 'Bank- und Finanzierungskosten'],
        'instandhaltung_reparatur_cent' => [HausgeldPositionKind::MAINTENANCE_AND_REPAIR, 'Instandhaltung, Instandsetzung und Reparaturen'],
        'rechts_prozesskosten_cent' => [HausgeldPositionKind::LEGAL_COST, 'Rechts- und Prozesskosten'],
    ];

    public function __construct(
        private readonly CategoryResolver $categories,
        private readonly HausgeldCostExtractor $extractor,
    ) {}

    public static function supports(Document $document): bool
    {
        $type = $document->getAttribute('document_type');

        return $type === DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL
            || $type === DocumentType::WEG_HAUSGELDABRECHNUNG_GESAMT;
    }

    public function reconcile(
        BillingRun $billingRun,
        Document $document,
        bool $externalHeatingStatementPresent = false,
        ?ExtractedFieldBag $bag = null,
    ): MappingOutcome {
        $bag ??= ExtractedFieldBag::forDocument($document);

        $sourceLabel = $this->sourceLabel($document);
        $documentId = (string) $document->getKey();

        $statement = $this->buildStatement($billingRun, $document, $bag);
        $result = $this->extractor->extract($statement, 'einheit-direkt', $externalHeatingStatementPresent);

        $proposals = [];
        $missing = [];
        $excluded = [];

        foreach ($result->acceptedCostItems as $item) {
            $positionKey = str_starts_with($item->costItemKey, 'weg-')
                ? mb_substr($item->costItemKey, 4)
                : $item->costItemKey;

            $categoryCode = $this->categories->proposeCode($item->categoryLabel) ?? $this->plausibleCode($item->categoryKey);
            $category = $this->categories->byCode($billingRun, $categoryCode);

            $apportionment = ApportionmentStatus::PRUEFPFLICHTIG;
            $excludedByDefault = false;

            if ($category instanceof CostCategory) {
                $status = $category->getAttribute('apportionment_status');
                $apportionment = $status instanceof ApportionmentStatus ? $status : ApportionmentStatus::PRUEFPFLICHTIG;
                $excludedByDefault = $category->getAttribute('excluded_from_apportionment_by_default') === true;
            }

            $amountPath = sprintf('kostenarten[%s].anteil_einheit_cent', $positionKey);
            $labelPath = sprintf('kostenarten[%s].bezeichnung', $positionKey);

            $proposals[] = new ProposedCostItem(
                sprintf('%s#weg-%s', $documentId, $positionKey),
                mb_substr($item->categoryLabel, 0, 190),
                $item->totalAmount->cents,
                $documentId,
                $bag->text('verwalter', 190),
                null,
                $bag->date('abrechnungszeitraum_bis'),
                Carbon::instance($statement->period->start),
                Carbon::instance($statement->period->end),
                $categoryCode,
                $apportionment,
                $excludedByDefault,
                Paragraph35aType::NONE,
                null,
                $bag->lowestConfidence($amountPath, $labelPath),
                $bag->firstPage($amountPath, $labelPath),
                $bag->firstExcerpt($amountPath, $labelPath),
                $category instanceof CostCategory && $category->getAttribute('is_heating_related') === true,
                $category instanceof CostCategory && $category->getAttribute('is_warm_water_related') === true,
                null,
                CostItemSource::KI_EXTRAKTION,
                $sourceLabel,
            );
        }

        foreach ($result->excludedPositions as $position) {
            $declared = false;

            foreach ($statement->positions as $input) {
                if ($input->positionKey === $position->positionKey) {
                    $declared = $input->declaredAllocable === true;

                    break;
                }
            }

            $excluded[] = new ExcludedPositionRow(
                $position->positionKey,
                $position->label,
                $position->unitShare->cents,
                $position->kind->value,
                $position->kind->label(),
                $position->reason,
                $declared,
            );
        }

        if (! $result->sufficientBreakdown) {
            $missing[] = new MissingRequirement(
                'Kostenaufschlüsselung der WEG-Abrechnung',
                sprintf(
                    'Aus %s liegt keine Aufschlüsselung nach Kostenarten vor. Aus dem monatlichen Hausgeld oder '
                    .'der Abrechnungsspitze allein lässt sich keine Betriebskostenabrechnung erstellen, weil '
                    .'darin nicht umlagefähige Anteile wie Verwaltung, Instandhaltung und Rücklage enthalten '
                    .'sind. Bitte reichen Sie die Einzelabrechnung beziehungsweise die Kostenaufstellung der '
                    .'Verwaltung nach.',
                    $sourceLabel
                ),
                $documentId,
                true,
            );
        }

        return new MappingOutcome($proposals, $missing, $excluded);
    }

    /**
     * Eingabe der Domain aus den ausgelesenen Inhaltsdaten.
     */
    public function buildStatement(BillingRun $billingRun, Document $document, ExtractedFieldBag $bag): HausgeldStatementInput
    {
        $positions = [];

        foreach ($bag->listIndexes('kostenarten') as $index) {
            $prefix = sprintf('kostenarten[%d]', $index);
            $share = $bag->integer($prefix.'.anteil_einheit_cent');

            if ($share === null) {
                continue;
            }

            $label = $bag->text($prefix.'.bezeichnung', 190) ?? 'Position ohne Bezeichnung';
            $kind = $this->kindFor($bag->text($prefix.'.kategorie', 60), $label);
            $total = $bag->integer($prefix.'.gesamtkosten_cent');

            $positions[] = new HausgeldPositionInput(
                (string) $index,
                $label,
                $this->categories->proposeCode($label) ?? 'UNKLAR',
                Money::fromCents($share),
                $kind,
                $total !== null ? Money::fromCents($total) : null,
                $bag->boolean($prefix.'.verwalter_kennzeichnung_umlagefaehig'),
            );
        }

        foreach (self::AGGREGATE_EXCLUSIONS as $path => [$kind, $label]) {
            $amount = $bag->integer($path);

            if ($amount === null) {
                continue;
            }

            $positions[] = new HausgeldPositionInput(
                $path,
                $label,
                'AUSGESCHLOSSEN',
                Money::fromCents($amount),
                $kind,
            );
        }

        $period = $this->period($billingRun, $bag);
        $total = $bag->integer('summe_anteil_einheit_cent');

        return new HausgeldStatementInput(
            $bag->text('einheitsbezeichnung', 120) ?? 'Einheit',
            $period,
            $positions,
            $total !== null ? Money::fromCents($total) : null,
            null,
            null,
            $bag->text('weg_bezeichnung', 190) ?? '',
        );
    }

    /**
     * Enthaelt die Abrechnung die Grundsteuer?
     */
    public function containsPropertyTax(BillingRun $billingRun, Document $document, ?ExtractedFieldBag $bag = null): bool
    {
        $bag ??= ExtractedFieldBag::forDocument($document);

        if ($bag->boolean('grundsteuer_enthalten') === true) {
            return true;
        }

        return $this->buildStatement($billingRun, $document, $bag)->containsPropertyTax();
    }

    private function kindFor(?string $category, string $label): HausgeldPositionKind
    {
        $byLabel = $this->categories->proposeCode($label);

        if ($byLabel === 'GRUNDSTEUER') {
            return HausgeldPositionKind::PROPERTY_TAX;
        }

        return match ($category) {
            'BETRIEBSKOSTEN' => HausgeldPositionKind::OPERATING_COST,
            'HEIZUNG_WARMWASSER' => HausgeldPositionKind::HEATING_COST,
            'RUECKLAGENZUFUEHRUNG' => HausgeldPositionKind::RESERVE_CONTRIBUTION,
            'RUECKLAGENENTNAHME' => HausgeldPositionKind::RESERVE_WITHDRAWAL,
            'INSTANDHALTUNG_INSTANDSETZUNG', 'REPARATUR' => HausgeldPositionKind::MAINTENANCE_AND_REPAIR,
            'VERWALTERVERGUETUNG' => HausgeldPositionKind::ADMINISTRATION_COST,
            'BANK_FINANZIERUNGSKOSTEN' => HausgeldPositionKind::BANK_AND_FINANCING_COST,
            'RECHTS_PROZESSKOSTEN' => HausgeldPositionKind::LEGAL_COST,
            // Ohne erkennbare Kostenart bleibt es eine Sammelposition. Das ist
            // die konservative Variante und fuehrt zum Ausschluss mit
            // Pruefaufgabe, nicht zur Uebernahme.
            default => HausgeldPositionKind::UNLABELLED_COLLECTIVE_POSITION,
        };
    }

    private function plausibleCode(string $categoryKey): ?string
    {
        return $categoryKey === 'UNKLAR' || $categoryKey === 'AUSGESCHLOSSEN' ? null : $categoryKey;
    }

    private function period(BillingRun $billingRun, ExtractedFieldBag $bag): DatePeriodRange
    {
        $start = $bag->date('abrechnungszeitraum_von');
        $end = $bag->date('abrechnungszeitraum_bis');

        if ($start instanceof Carbon && $end instanceof Carbon && $start <= $end) {
            return new DatePeriodRange($start->toDateTimeImmutable(), $end->toDateTimeImmutable());
        }

        return $this->billingPeriod($billingRun);
    }

    public function billingPeriod(BillingRun $billingRun): DatePeriodRange
    {
        $start = $billingRun->getAttribute('period_start');
        $end = $billingRun->getAttribute('period_end');

        return new DatePeriodRange(
            $start instanceof Carbon ? $start->toDateTimeImmutable() : Carbon::now()->startOfYear()->toDateTimeImmutable(),
            $end instanceof Carbon ? $end->toDateTimeImmutable() : Carbon::now()->endOfYear()->toDateTimeImmutable(),
        );
    }

    private function sourceLabel(Document $document): string
    {
        $label = $document->getAttribute('source_label');

        return is_string($label) && $label !== '' ? $label : 'der WEG-Abrechnung';
    }
}
