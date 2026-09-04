<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\BillingRun\BillingRunProgress;
use App\Application\Reconciliation\Dto\HeatingMatrix;
use App\Application\Reconciliation\Dto\MappingOutcome;
use App\Application\Reconciliation\Dto\PropertyTaxOutcome;
use App\Application\Reconciliation\Dto\ReconciliationOutcome;
use App\Application\Reconciliation\Support\ExtractedFieldBag;
use App\Domain\Calculation\Weg\HausgeldStatementInput;
use App\Domain\Money\Money;
use App\Enums\CostItemStatus;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Enums\ValidationIssueStatus;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\ValidationIssue;
use Illuminate\Support\Facades\DB;

/**
 * Fuehrt die Reconciliation eines Abrechnungslaufs aus.
 *
 * Reihenfolge, fachlich begruendet:
 *
 *  1. Heizkosten-Matrix bilden. Erst danach ist bekannt, ob eine externe
 *     Heizkostenabrechnung vorliegt und die WEG-Summenposition damit nur noch
 *     Vergleichssumme ist (Abschnitt 7.4).
 *  2. WEG-Einzelabrechnungen uebernehmen, Ausschluesse nach Abschnitt 7.2
 *     getrennt ausweisen.
 *  3. Belegartige Unterlagen abbilden.
 *  4. Grundsteuer nur ergaenzen, wenn sie nicht bereits enthalten ist
 *     (Abschnitt 7.3).
 *  5. Dubletten auf Positionsebene pruefen.
 *
 * Der Lauf ist wiederholbar. Er entfernt zuvor nur seine eigenen offenen
 * Pruefaufgaben und die noch nicht entschiedenen maschinellen Vorschlaege.
 * Bestaetigte, bearbeitete, verworfene und manuell erfasste Positionen sowie
 * alle ausgelesenen Inhaltsdaten bleiben unveraendert.
 */
final class ReconcileBillingRun
{
    public function __construct(
        private readonly CostItemMapper $mapper,
        private readonly HausgeldReconciler $hausgeld,
        private readonly PropertyTaxReconciler $propertyTax,
        private readonly HeatingReconciler $heating,
        private readonly PositionDuplicateScanner $duplicates,
        private readonly CostItemProposalWriter $writer,
        private readonly IssueRecorder $issues,
        private readonly BillingModeAdvisor $advisor,
        private readonly BillingRunProgress $progress,
    ) {}

    public function run(BillingRun $billingRun): ReconciliationOutcome
    {
        /** @var ReconciliationOutcome $outcome */
        $outcome = DB::transaction(fn (): ReconciliationOutcome => $this->execute($billingRun));

        // Die Zuordnung ist abgeschlossen. Entstehen dabei zu entscheidende
        // Vorschlaege oder offene Pruefaufgaben, ist die Kostenpruefung
        // erforderlich und der Lauf geht auf REVIEW_REQUIRED. Der
        // Statuswechsel liegt bewusst hinter der Transaktion, damit ein
        // Rollback der Zuordnung keinen Statuswechsel hinterlaesst.
        if ($outcome->proposalsCreated > 0 || $outcome->openIssueCount > 0) {
            $this->progress->pruefungErforderlich($billingRun);
        }

        return $outcome;
    }

    private function execute(BillingRun $billingRun): ReconciliationOutcome
    {
        $this->issues->clearOpen($billingRun);
        $this->writer->clearUndecidedProposals($billingRun);

        $documents = $this->documents($billingRun);
        $matrix = $this->heating->matrix($billingRun, $documents);
        $externalHeating = $matrix->externalStatementPresent;

        $created = 0;
        $excludedRows = [];
        $categoryCodes = [];
        $wegStatement = null;
        $wegDocument = null;

        foreach ($documents as $document) {
            if (! HausgeldReconciler::supports($document)) {
                continue;
            }

            $bag = ExtractedFieldBag::forDocument($document);
            $result = $this->hausgeld->reconcile($billingRun, $document, $externalHeating, $bag);

            $created += count($this->writer->write($billingRun, $result));
            $excludedRows = array_merge($excludedRows, $result->excluded);
            $categoryCodes = array_merge($categoryCodes, $this->categoryCodes($result));

            $this->recordExclusionIssues($billingRun, $document, $result);

            if ($document->getAttribute('document_type') === DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL) {
                $wegStatement ??= $this->hausgeld->buildStatement($billingRun, $document, $bag);
                $wegDocument ??= $document;
            }
        }

        foreach ($documents as $document) {
            if (! CostItemMapper::supports($document)) {
                continue;
            }

            $result = $this->mapper->map($billingRun, $document);

            $created += count($this->writer->write($billingRun, $result));
            $categoryCodes = array_merge($categoryCodes, $this->categoryCodes($result));
        }

        // Externe Heizkostenabrechnung (Fall A): die Einzelbetraege je Einheit
        // werden als Heizkostenpositionen vorgeschlagen, weil die
        // WEG-Summenposition in diesem Fall ausgeschlossen ist. Wie in der
        // Matrix zaehlt die erste externe Abrechnung.
        foreach ($documents as $document) {
            if ($document->getAttribute('document_type') !== DocumentType::HEIZKOSTENABRECHNUNG) {
                continue;
            }

            $result = $this->heating->proposals($billingRun, $document);

            $created += count($this->writer->write($billingRun, $result));
            $categoryCodes = array_merge($categoryCodes, $this->categoryCodes($result));

            break;
        }

        $propertyTaxOutcome = null;

        foreach ($documents as $document) {
            if (! PropertyTaxReconciler::supports($document)) {
                continue;
            }

            $statement = $wegStatement ?? new HausgeldStatementInput(
                'Einheit',
                $this->hausgeld->billingPeriod($billingRun),
                [],
            );

            $propertyTaxOutcome = $this->propertyTax->reconcile(
                $billingRun,
                $document,
                $statement,
                array_values(array_unique($categoryCodes)),
            );

            $created += count($this->writer->write($billingRun, $propertyTaxOutcome->mapping));

            $this->recordPropertyTaxIssues($billingRun, $document, $propertyTaxOutcome);

            break;
        }

        $this->recordHeatingIssues($billingRun, $matrix, $wegDocument);

        $findings = $this->duplicates->scan($billingRun);
        $this->duplicates->persist($billingRun, $findings);

        return new ReconciliationOutcome(
            count($documents),
            $created,
            array_values($excludedRows),
            $findings,
            $matrix,
            $propertyTaxOutcome,
            $this->advisor->suggest($billingRun),
            $this->openIssueCount($billingRun),
            $this->hasOpenBlocker($billingRun),
        );
    }

    /**
     * @return list<Document>
     */
    public function documents(BillingRun $billingRun): array
    {
        $documents = Document::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('processing_status', DocumentProcessingStatus::ABGESCHLOSSEN->value)
            ->orderBy('sequence_number')
            ->get()
            ->all();

        return array_values($documents);
    }

    private function recordExclusionIssues(BillingRun $billingRun, Document $document, MappingOutcome $result): void
    {
        foreach ($result->excluded as $row) {
            $this->issues->hint(
                $billingRun,
                RuleCode::WEG_POSITION_EXCLUDED,
                sprintf('Nicht übernommen: %s', $row->label),
                sprintf(
                    'Die Position "%s" über %s ist als %s eingeordnet und wird getrennt ausgewiesen, aber nicht '
                    .'auf den Mieter umgelegt. %s',
                    $row->label,
                    Money::fromCents($row->amountCent)->format(),
                    $row->kindLabel,
                    $row->reason
                ),
                Document::class,
                (string) $document->getKey(),
            );

            if (! $row->declaredAllocableByManager) {
                continue;
            }

            $this->issues->warning(
                $billingRun,
                RuleCode::WEG_MANAGER_FLAG_NOT_A_RELEASE,
                sprintf('Kennzeichnung der Verwaltung prüfen: %s', $row->label),
                sprintf(
                    'Die Position "%s" ist in der WEG-Abrechnung als umlagefähig gekennzeichnet, gehört aber zu '
                    .'%s. Die Kennzeichnung der Verwaltung ist ein Vorschlag und keine Freigabe. Die Position '
                    .'bleibt ausgeschlossen. Maßgeblich sind Mietvertrag und Kostenart. Das ist eine allgemeine '
                    .'Information und keine Rechtsberatung im Einzelfall.',
                    $row->label,
                    $row->kindLabel
                ),
                Document::class,
                (string) $document->getKey(),
            );
        }
    }

    private function recordPropertyTaxIssues(BillingRun $billingRun, Document $document, PropertyTaxOutcome $outcome): void
    {
        if ($outcome->possibleDuplicate) {
            $this->issues->warning(
                $billingRun,
                RuleCode::PROPERTY_TAX_POSSIBLE_DUPLICATE,
                'Grundsteuer möglicherweise doppelt',
                $outcome->explanation,
                Document::class,
                (string) $document->getKey(),
            );

            return;
        }

        if ($outcome->needsPeriodConfirmation) {
            $this->issues->warning(
                $billingRun,
                RuleCode::PROPERTY_TAX_NEEDS_CONFIRMATION,
                'Grundsteuer bestätigen',
                $outcome->explanation,
                Document::class,
                (string) $document->getKey(),
            );
        }
    }

    private function recordHeatingIssues(BillingRun $billingRun, HeatingMatrix $matrix, ?Document $wegDocument): void
    {
        if ($matrix->externalStatementPresent && $wegDocument instanceof Document) {
            $this->issues->hint(
                $billingRun,
                RuleCode::HEATING_DOUBLE_COUNT_PREVENTED,
                'Heizkosten nur aus der externen Abrechnung',
                'Es liegt eine externe Heizkostenabrechnung vor. Die Heizkostenposition der Hausgeldabrechnung '
                .'wird deshalb nur als Vergleichssumme geführt und nicht zusätzlich angesetzt. So entsteht keine '
                .'Doppelzählung.',
                Document::class,
                (string) $wegDocument->getKey(),
            );
        }

        if ($matrix->blocksFinalization && $matrix->blockingExplanation !== null) {
            $this->issues->blocker(
                $billingRun,
                RuleCode::HEATING_CHECKSUM_OUT_OF_TOLERANCE,
                'Prüfsumme der Heizkostenabrechnung weicht ab',
                $matrix->blockingExplanation,
            );
        }

        foreach ($matrix->missing as $requirement) {
            $this->issues->warning(
                $billingRun,
                RuleCode::MISSING_MANDATORY,
                sprintf('Angabe fehlt: %s', $requirement->fieldLabel),
                $requirement->explanation,
                $requirement->documentId === null ? null : Document::class,
                $requirement->documentId,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function categoryCodes(MappingOutcome $result): array
    {
        $codes = [];

        foreach ($result->proposals as $proposal) {
            if ($proposal->categoryCode !== null) {
                $codes[] = $proposal->categoryCode;
            }
        }

        return $codes;
    }

    private function openIssueCount(BillingRun $billingRun): int
    {
        return ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->open()
            ->count();
    }

    private function hasOpenBlocker(BillingRun $billingRun): bool
    {
        return ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->openBlockers()
            ->exists();
    }

    /**
     * Zahl der vorgeschlagenen, noch nicht entschiedenen Positionen.
     */
    public function openProposalCount(BillingRun $billingRun): int
    {
        return CostItem::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', CostItemStatus::VORGESCHLAGEN->value)
            ->count();
    }

    /**
     * Sind noch Pruefaufgaben offen?
     */
    public function hasOpenIssues(BillingRun $billingRun): bool
    {
        return ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', ValidationIssueStatus::OFFEN->value)
            ->exists();
    }
}
