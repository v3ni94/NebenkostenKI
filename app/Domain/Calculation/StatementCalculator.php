<?php

declare(strict_types=1);

namespace App\Domain\Calculation;

use App\Domain\Allocation\AllocationKey;
use App\Domain\Allocation\AllocationKeyScope;
use App\Domain\Allocation\ConsumptionKey;
use App\Domain\Allocation\InvalidAllocationKeyException;
use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Dto\OccupancyInput;
use App\Domain\Calculation\Dto\PrepaymentInput;
use App\Domain\Calculation\Dto\StatementCalculationInput;
use App\Domain\Calculation\Result\CalculationRunResult;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Calculation\Result\ExcludedCost;
use App\Domain\Calculation\Result\OwnerOverviewResult;
use App\Domain\Calculation\Result\OwnerVacancyShare;
use App\Domain\Calculation\Result\ResidualShare;
use App\Domain\Calculation\Result\StatementLine;
use App\Domain\Calculation\Result\UnitStatementResult;
use App\Domain\Calculation\Rounding\DistributionResult;
use App\Domain\Calculation\Rounding\LargestRemainderDistributor;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Domain\Period\PeriodCoverage;
use App\Domain\Period\TimeFactor;
use Brick\Math\BigRational;

/**
 * Deterministische Berechnungsengine für Betriebskostenabrechnungen.
 *
 * Reine PHP-Klasse ohne Abhängigkeit zu HTTP, Laravel-Facades, Eloquent,
 * Stripe oder einem KI-Provider. Sie erhält validierte Eingabedaten und
 * liefert reproduzierbare Ergebnisse plus nachvollziehbaren Rechenweg.
 *
 * VERBINDLICHER RECHENWEG
 *
 * 1. Beteiligte bilden: Je Einheit werden die übergebenen Mietverhältnisse
 *    und Leerstände auf den Abrechnungszeitraum begrenzt. Nicht belegte
 *    Zeiträume ergänzt die Engine als OWNER_RESIDUAL. Damit überdecken die
 *    Beteiligten einer Einheit den Abrechnungszeitraum lückenlos; Kosten
 *    können niemals stillschweigend auf die übrigen Mieter verschoben
 *    werden. Überschneidungen sind ein harter Eingabefehler.
 *
 * 2. Gewichte je Kostenzeile bestimmen:
 *    - Schlüssel mit Bezugsebene UNIT:
 *      Gewicht = (Zähler der Einheit / Nenner) × (Nutzungstage / Tage des
 *      Abrechnungszeitraums).
 *    - Schlüssel mit Bezugsebene OCCUPANCY (Personentage, Verbrauch,
 *      Direktzuordnung): Gewicht = Zähler des Nutzungszeitraums / Nenner.
 *      Der Zeitanteil ist bereits im Zähler enthalten, der zusätzliche
 *      Zeitfaktor ist deshalb exakt 1.
 *    Alle Gewichte sind exakte Brüche (BigRational), es gibt keine
 *    Zwischenrundung.
 *
 * 3. Restanteil: Ergibt die Summe der Gewichte weniger als 1 (z. B. weil der
 *    MEA-Nenner die gesamte WEG abbildet, das Objekt aber nur einen Teil),
 *    wird der fehlende Anteil als ResidualShare dem Eigentümer zugerechnet
 *    und ausgewiesen.
 *
 * 4. Rundung: Erst am Ende einer Kostenzeile wird auf Cent gerundet. Die
 *    Verteilung erfolgt mit dem Largest-Remainder-Verfahren; bei Gleichstand
 *    entscheidet der Beteiligtenschlüssel aufsteigend. Die Summe der
 *    Einzelanteile entspricht exakt dem verteilten Betrag. Die Korrektur
 *    gegenüber der kaufmännischen Rundung steht als roundingAdjustmentCent
 *    in der Zeile.
 *
 * 5. Umlagefähigkeit: Nicht umlagefähige und prüfpflichtige Positionen sind
 *    standardmäßig ausgeschlossen. Eine Einbeziehung erfolgt nur bei
 *    ausdrücklicher Begründung und wird in der Zeile gekennzeichnet. Die
 *    Engine trifft KEINE juristische Freigabe.
 *
 * 6. Vorauszahlungen: Abgezogen werden ausschließlich die tatsächlich
 *    geleisteten Vorauszahlungen. Sollwerte dienen der Plausibilisierung.
 *
 * 7. Ergebnis: balance positiv = Nachzahlung, negativ = Guthaben.
 */
final class StatementCalculator
{
    /**
     * Schlüssel des nicht verteilten Restanteils. Die Tilde sortiert in
     * ASCII hinter allen alphanumerischen Beteiligtenschlüsseln, damit das
     * Largest-Remainder-Verfahren stabil bleibt.
     */
    private const string RESIDUAL_KEY = '~RESIDUAL';

    private LargestRemainderDistributor $distributor;

    public function __construct(?LargestRemainderDistributor $distributor = null)
    {
        $this->distributor = $distributor ?? new LargestRemainderDistributor;
    }

    public function calculate(StatementCalculationInput $input): CalculationRunResult
    {
        /** @var list<CheckFinding> $findings */
        $findings = [];

        $participants = $this->buildParticipants($input, $findings);

        /** @var array<string, list<StatementLine>> $linesByParticipant */
        $linesByParticipant = [];
        /** @var array<string, array<string, int>> $laborShareByParticipant participantKey => (category => cent) */
        $laborShareByParticipant = [];
        /** @var list<ExcludedCost> $excludedCosts */
        $excludedCosts = [];
        /** @var list<ResidualShare> $residualShares */
        $residualShares = [];

        $includedCostTotal = Money::zero();
        $excludedCostTotal = Money::zero();

        $seenCostItemKeys = [];

        foreach ($input->costItems as $item) {
            if (isset($seenCostItemKeys[$item->costItemKey])) {
                throw CalculationInputException::duplicateCostItemKey($item->costItemKey);
            }

            $seenCostItemKeys[$item->costItemKey] = true;

            $this->checkServicePeriod($item, $input->billingPeriod, $findings);

            if (! $item->isIncludedInTenantAllocation()) {
                $excludedCosts[] = new ExcludedCost(
                    $item->costItemKey,
                    $item->categoryKey,
                    $item->categoryLabel,
                    $item->totalAmount,
                    $item->allocabilityStatus,
                    sprintf(
                        'Standardmäßig %s und daher nicht auf Mieter umgelegt.',
                        $item->allocabilityStatus->label()
                    ),
                    $item->documentReference
                );
                $excludedCostTotal = $excludedCostTotal->plus($item->totalAmount);

                $findings[] = CheckFinding::info(
                    CheckCode::NOT_ALLOCABLE_EXCLUDED,
                    sprintf(
                        'Die Position "%s" (%s) ist %s und wurde nicht auf die Mieter umgelegt.',
                        $item->categoryLabel,
                        $item->totalAmount->format(),
                        $item->allocabilityStatus->label()
                    ),
                    ['costItemKey' => $item->costItemKey, 'status' => $item->allocabilityStatus->value]
                );

                continue;
            }

            if ($item->isIncludedByOverride()) {
                $findings[] = CheckFinding::warning(
                    CheckCode::NOT_ALLOCABLE_INCLUDED_BY_OVERRIDE,
                    sprintf(
                        'Die Position "%s" (%s) ist standardmäßig %s und wurde auf ausdrückliche Entscheidung '
                        .'einbezogen. Begründung: %s. Dies ist keine juristische Freigabe.',
                        $item->categoryLabel,
                        $item->totalAmount->format(),
                        $item->allocabilityStatus->label(),
                        (string) $item->inclusionOverrideReason
                    ),
                    ['costItemKey' => $item->costItemKey, 'status' => $item->allocabilityStatus->value]
                );
            }

            if ($item->totalAmount->isNegative()) {
                $findings[] = CheckFinding::info(
                    CheckCode::CREDIT_NOTE_APPLIED,
                    sprintf(
                        'Die Position "%s" ist eine Gutschrift über %s und wird mit demselben Schlüssel verteilt.',
                        $item->categoryLabel,
                        $item->totalAmount->format()
                    ),
                    ['costItemKey' => $item->costItemKey]
                );
            }

            $includedCostTotal = $includedCostTotal->plus($item->totalAmount);

            $allocationKey = $input->allocationKeys[$item->allocationKeyRef] ?? null;

            if (! $allocationKey instanceof AllocationKey) {
                throw CalculationInputException::unknownAllocationKey($item->costItemKey, $item->allocationKeyRef);
            }

            $weights = $this->weightsFor($allocationKey, $participants, $input);
            $distribution = $this->distributor->distribute($item->totalAmount->cents, $weights);
            $laborDistribution = $this->distributeLaborShare($item, $weights);

            foreach ($participants as $participantKey => $participant) {
                if (! array_key_exists($participantKey, $weights)) {
                    continue;
                }

                $line = $this->buildLine(
                    $item,
                    $allocationKey,
                    $participant,
                    $input->billingPeriod,
                    $distribution,
                    $laborDistribution
                );

                $linesByParticipant[$participantKey][] = $line;

                if ($line->taxBenefitLaborShare instanceof Money) {
                    $category = $item->taxBenefitCategory->value;
                    $laborShareByParticipant[$participantKey][$category] =
                        ($laborShareByParticipant[$participantKey][$category] ?? 0) + $line->taxBenefitLaborShare->cents;
                }
            }

            $residualCents = $distribution->amountFor(self::RESIDUAL_KEY);

            if (array_key_exists(self::RESIDUAL_KEY, $weights) && $residualCents !== 0) {
                $residualShares[] = new ResidualShare(
                    $item->costItemKey,
                    $item->categoryKey,
                    $item->categoryLabel,
                    $item->totalAmount,
                    Money::fromCents($residualCents),
                    sprintf(
                        'Nicht auf die erfassten Einheiten verteilter Anteil des Schlüssels "%s"; '
                        .'der Anteil verbleibt beim Eigentümer.',
                        $allocationKey->label()
                    )
                );

                $findings[] = CheckFinding::warning(
                    CheckCode::UNALLOCATED_RESIDUAL,
                    sprintf(
                        'Von der Position "%s" verbleiben %s beim Eigentümer, weil der Verteilerschlüssel "%s" '
                        .'nicht vollständig durch die erfassten Einheiten abgedeckt ist.',
                        $item->categoryLabel,
                        Money::fromCents($residualCents)->format(),
                        $allocationKey->label()
                    ),
                    ['costItemKey' => $item->costItemKey, 'residualCent' => $residualCents]
                );
            }

            if ($item->hasUndisclosedLaborShare()) {
                $findings[] = CheckFinding::warning(
                    CheckCode::UNDISCLOSED_LABOR_SHARE,
                    sprintf(
                        'Für die Position "%s" ist der nach § 35a EStG begünstigte Lohnanteil nicht ausgewiesen. '
                        .'Es wird kein begünstigter Betrag ausgewiesen.',
                        $item->categoryLabel
                    ),
                    ['costItemKey' => $item->costItemKey]
                );
            }
        }

        $statements = [];
        $vacancyShares = [];
        $allocatedToTenantsTotal = Money::zero();
        $vacancyTotal = Money::zero();

        foreach ($participants as $participantKey => $participant) {
            $lines = $linesByParticipant[$participantKey] ?? [];
            $total = Money::zero();

            foreach ($lines as $line) {
                $total = $total->plus($line->share);
            }

            if ($participant->isTenancy()) {
                $statement = $this->buildStatement($input, $participant, $lines, $total, $laborShareByParticipant[$participantKey] ?? [], $findings);
                $statements[] = $statement;
                $allocatedToTenantsTotal = $allocatedToTenantsTotal->plus($total);

                continue;
            }

            $vacancyShares[] = new OwnerVacancyShare(
                $participant->participantKey,
                $participant->unitKey,
                $input->unit($participant->unitKey)->label ?? $participant->unitKey,
                $participant->kind,
                $participant->period,
                $lines,
                $total
            );
            $vacancyTotal = $vacancyTotal->plus($total);
        }

        $residualTotal = Money::zero();

        foreach ($residualShares as $residual) {
            $residualTotal = $residualTotal->plus($residual->amount);
        }

        $ownerOverview = new OwnerOverviewResult(
            $input->billingPeriod,
            $input->propertyLabel,
            $statements,
            $vacancyShares,
            $excludedCosts,
            $residualShares,
            $includedCostTotal,
            $allocatedToTenantsTotal,
            $vacancyTotal,
            $residualTotal,
            $excludedCostTotal
        );

        $findings[] = $ownerOverview->isBalanced()
            ? CheckFinding::passed(
                CheckCode::CHECKSUM_BALANCED,
                sprintf(
                    'Prüfsumme stimmt: Mieteranteile %s, Leerstand %s, Restanteile %s ergeben die einbezogenen '
                    .'Kosten von %s.',
                    $allocatedToTenantsTotal->format(),
                    $vacancyTotal->format(),
                    $residualTotal->format(),
                    $includedCostTotal->format()
                )
            )
            : CheckFinding::blocker(
                CheckCode::CHECKSUM_UNBALANCED,
                sprintf(
                    'Prüfsumme stimmt nicht: Abweichung %s zwischen verteilten Anteilen und einbezogenen Kosten.',
                    $ownerOverview->checksumDifference()->format()
                ),
                ['differenceCent' => $ownerOverview->checksumDifference()->cents]
            );

        return new CalculationRunResult($statements, $ownerOverview, $findings);
    }

    /**
     * Bildet die Beteiligten je Einheit und ergänzt nicht belegte Zeiträume.
     *
     * @param  list<CheckFinding>  $findings
     * @return array<string, AllocationParticipant>
     */
    private function buildParticipants(StatementCalculationInput $input, array &$findings): array
    {
        if ($input->units === []) {
            throw CalculationInputException::noUnits();
        }

        $unitKeys = [];

        foreach ($input->units as $unit) {
            if (isset($unitKeys[$unit->unitKey])) {
                throw CalculationInputException::duplicateUnitKey($unit->unitKey);
            }

            $unitKeys[$unit->unitKey] = true;
        }

        $seenOccupancyKeys = [];

        foreach ($input->occupancies as $occupancy) {
            if (isset($seenOccupancyKeys[$occupancy->occupancyKey])) {
                throw CalculationInputException::duplicateOccupancyKey($occupancy->occupancyKey);
            }

            $seenOccupancyKeys[$occupancy->occupancyKey] = true;

            if (! isset($unitKeys[$occupancy->unitKey])) {
                throw CalculationInputException::unknownUnit($occupancy->occupancyKey, $occupancy->unitKey);
            }
        }

        /** @var array<string, AllocationParticipant> $participants */
        $participants = [];

        foreach ($input->units as $unit) {
            /** @var array<string, DatePeriodRange> $clipped */
            $clipped = [];
            /** @var array<string, OccupancyInput> $occupancyByKey */
            $occupancyByKey = [];

            foreach ($input->occupanciesForUnit($unit->unitKey) as $occupancy) {
                $intersection = $input->billingPeriod->intersect($occupancy->period);

                if (! $intersection instanceof DatePeriodRange) {
                    throw CalculationInputException::occupancyOutsideBillingPeriod(
                        $occupancy->occupancyKey,
                        $occupancy->period,
                        $input->billingPeriod
                    );
                }

                $clipped[$occupancy->occupancyKey] = $intersection;
                $occupancyByKey[$occupancy->occupancyKey] = $occupancy;
            }

            foreach (PeriodCoverage::overlappingPairs($clipped) as [$firstKey, $secondKey, $overlap]) {
                throw OverlappingOccupancyException::between($unit->unitKey, $firstKey, $secondKey, $overlap);
            }

            foreach ($clipped as $occupancyKey => $period) {
                $occupancy = $occupancyByKey[$occupancyKey];

                $participants[$occupancyKey] = new AllocationParticipant(
                    $occupancyKey,
                    $unit->unitKey,
                    $occupancy->kind,
                    $period,
                    $occupancy->label !== '' ? $occupancy->label : $occupancy->kind->label()
                );
            }

            $gaps = PeriodCoverage::gapsWithin($input->billingPeriod, array_values($clipped));

            if ($gaps === []) {
                continue;
            }

            $index = 0;

            foreach ($gaps as $gap) {
                $index++;
                $residualKey = sprintf('%s#leerstand-%d', $unit->unitKey, $index);

                $participants[$residualKey] = new AllocationParticipant(
                    $residualKey,
                    $unit->unitKey,
                    OccupancyKind::OWNER_RESIDUAL,
                    $gap,
                    sprintf('Nicht belegter Zeitraum %s', $gap->format())
                );

                $findings[] = CheckFinding::warning(
                    CheckCode::COVERAGE_GAP,
                    sprintf(
                        'Für die Einheit "%s" ist der Zeitraum %s (%d Tage) nicht belegt. Die Anteile dieses '
                        .'Zeitraums werden dem Eigentümer zugerechnet und nicht auf Mieter umgelegt.',
                        $unit->label,
                        $gap->format(),
                        $gap->days()
                    ),
                    ['unitKey' => $unit->unitKey, 'days' => $gap->days()]
                );
            }
        }

        ksort($participants);

        return $participants;
    }

    /**
     * Berechnet die exakten Gewichte einer Kostenzeile.
     *
     * @param  array<string, AllocationParticipant>  $participants
     * @return array<string, BigRational>
     */
    private function weightsFor(
        AllocationKey $allocationKey,
        array $participants,
        StatementCalculationInput $input,
    ): array {
        $periodDays = $input->billingPeriod->days();
        $weights = [];

        if ($allocationKey->scope() === AllocationKeyScope::UNIT) {
            foreach ($input->units as $unit) {
                if (! $allocationKey->hasParticipant($unit->unitKey)) {
                    throw InvalidAllocationKeyException::missingUnit($allocationKey->type(), $unit->unitKey);
                }

                $unitShare = $allocationKey->shareFor($unit->unitKey);

                foreach ($participants as $participantKey => $participant) {
                    if ($participant->unitKey !== $unit->unitKey) {
                        continue;
                    }

                    $weights[$participantKey] = $unitShare->multipliedBy(
                        BigRational::nd($participant->days(), $periodDays)
                    );
                }
            }
        } else {
            foreach ($participants as $participantKey => $participant) {
                $weights[$participantKey] = $allocationKey->shareFor($participantKey);
            }
        }

        $sum = BigRational::zero();

        foreach ($weights as $weight) {
            $sum = $sum->plus($weight);
        }

        if ($sum->isLessThan(BigRational::one())) {
            $weights[self::RESIDUAL_KEY] = BigRational::one()->minus($sum);
        }

        return $weights;
    }

    /**
     * Verteilt einen ausgewiesenen § 35a Lohnanteil mit denselben Gewichten
     * wie die Kostenzeile, damit die Summen exakt bleiben.
     *
     * @param  array<string, BigRational>  $weights
     */
    private function distributeLaborShare(CostItemInput $item, array $weights): ?DistributionResult
    {
        $laborShare = $item->benefitedLaborShare();

        if (! $laborShare instanceof Money) {
            return null;
        }

        return $this->distributor->distribute($laborShare->cents, $weights);
    }

    private function buildLine(
        CostItemInput $item,
        AllocationKey $allocationKey,
        AllocationParticipant $participant,
        DatePeriodRange $billingPeriod,
        DistributionResult $distribution,
        ?DistributionResult $laborDistribution,
    ): StatementLine {
        $isUnitScope = $allocationKey->scope() === AllocationKeyScope::UNIT;
        $keyReference = $isUnitScope ? $participant->unitKey : $participant->participantKey;

        $timeFactor = $isUnitScope
            ? TimeFactor::applied($participant->days(), $billingPeriod->days())
            : TimeFactor::includedInKey($participant->days(), $billingPeriod->days());

        $substitute = $allocationKey instanceof ConsumptionKey
            && $allocationKey->usesSubstituteDistributionFor($participant->participantKey);

        $laborShare = null;

        if ($laborDistribution instanceof DistributionResult) {
            $laborShare = Money::fromCents($laborDistribution->amountFor($participant->participantKey));
        }

        return new StatementLine(
            $item->costItemKey,
            $item->categoryKey,
            $item->categoryLabel,
            $item->totalAmount,
            $allocationKey->label(),
            $allocationKey->explanationFor($keyReference),
            $allocationKey->formattedNumeratorFor($keyReference),
            $allocationKey->formattedDenominator(),
            $timeFactor,
            Money::fromCents($distribution->amountFor($participant->participantKey)),
            $distribution->adjustmentFor($participant->participantKey),
            $item->allocabilityStatus,
            $item->isIncludedByOverride(),
            $item->inclusionOverrideReason,
            $item->taxBenefitCategory,
            $laborShare,
            $item->laborShareDisclosed,
            $substitute,
            $item->documentReference
        );
    }

    /**
     * @param  list<StatementLine>  $lines
     * @param  array<string, int>  $laborShares
     * @param  list<CheckFinding>  $findings
     */
    private function buildStatement(
        StatementCalculationInput $input,
        AllocationParticipant $participant,
        array $lines,
        Money $allocableTotal,
        array $laborShares,
        array &$findings,
    ): UnitStatementResult {
        $prepayment = $input->prepaymentFor($participant->participantKey);
        $assumptions = [];
        $statementFindings = [];

        $target = $prepayment->targetAmount ?? Money::zero();
        $actual = $prepayment?->deductibleAmount() ?? Money::zero();
        $assumed = $prepayment->assumedFromTarget ?? false;

        if (! $prepayment instanceof PrepaymentInput) {
            $finding = CheckFinding::warning(
                CheckCode::PREPAYMENT_MISSING,
                sprintf(
                    'Für das Mietverhältnis "%s" sind keine Vorauszahlungen übergeben worden. Es wurden 0,00 EUR '
                    .'abgezogen.',
                    $participant->label
                ),
                ['occupancyKey' => $participant->participantKey]
            );
            $findings[] = $finding;
            $statementFindings[] = $finding;
            $assumptions[] = 'Es wurden keine Vorauszahlungen berücksichtigt, weil keine Zahlungsdaten vorliegen.';
        }

        if ($assumed) {
            $finding = CheckFinding::warning(
                CheckCode::PREPAYMENT_ASSUMED_FROM_TARGET,
                sprintf(
                    'Für das Mietverhältnis "%s" lagen keine Ist-Vorauszahlungen vor. Es wurden die vereinbarten '
                    .'Sollwerte in Höhe von %s übernommen; die Übernahme ist ausdrücklich bestätigt.',
                    $participant->label,
                    $target->format()
                ),
                ['occupancyKey' => $participant->participantKey]
            );
            $findings[] = $finding;
            $statementFindings[] = $finding;
            $assumptions[] = sprintf(
                'Die tatsächlich geleisteten Vorauszahlungen lagen nicht vor. Übernommen wurden die vereinbarten '
                .'Sollvorauszahlungen von %s (ausdrücklich bestätigte Annahme).',
                $target->format()
            );
        } elseif ($prepayment instanceof PrepaymentInput && ! $actual->equals($target)) {
            $finding = CheckFinding::info(
                CheckCode::PREPAYMENT_DEVIATION,
                sprintf(
                    'Die tatsächlich geleisteten Vorauszahlungen (%s) weichen von den vereinbarten Sollwerten (%s) '
                    .'um %s ab.',
                    $actual->format(),
                    $target->format(),
                    $prepayment->deviation()->format()
                ),
                ['occupancyKey' => $participant->participantKey, 'deviationCent' => $prepayment->deviation()->cents]
            );
            $findings[] = $finding;
            $statementFindings[] = $finding;
        }

        if (! $participant->period->equals($input->billingPeriod)) {
            $assumptions[] = sprintf(
                'Der Nutzungszeitraum umfasst %s, also %d von %d Tagen des Abrechnungszeitraums.',
                $participant->period->format(),
                $participant->days(),
                $input->billingPeriod->days()
            );
        }

        foreach ($lines as $line) {
            if ($line->substituteDistributionConfirmed) {
                $assumptions[] = sprintf(
                    'Für die Position "%s" wurde der Verbrauch ohne Zwischenablesung ersatzweise taggenau verteilt '
                    .'(ausdrücklich bestätigte Ersatzverteilung).',
                    $line->categoryLabel
                );

                $finding = CheckFinding::warning(
                    CheckCode::SUBSTITUTE_CONSUMPTION_DISTRIBUTION,
                    sprintf(
                        'Die Verbrauchsaufteilung der Position "%s" für "%s" beruht auf einer bestätigten '
                        .'Ersatzverteilung ohne Zwischenablesung.',
                        $line->categoryLabel,
                        $participant->label
                    ),
                    ['occupancyKey' => $participant->participantKey, 'costItemKey' => $line->costItemKey]
                );
                $findings[] = $finding;
                $statementFindings[] = $finding;
            }

            if ($line->includedByOverride) {
                $assumptions[] = sprintf(
                    'Die Position "%s" ist standardmäßig %s und wurde auf ausdrückliche Entscheidung einbezogen. '
                    .'Begründung: %s.',
                    $line->categoryLabel,
                    $line->allocabilityStatus->label(),
                    (string) $line->inclusionOverrideReason
                );
            }

            if ($line->hasUndisclosedLaborShare()) {
                $assumptions[] = sprintf(
                    'Für die Position "%s" ist der Lohnanteil nach § 35a EStG nicht ausgewiesen; es wird kein '
                    .'begünstigter Betrag ausgewiesen.',
                    $line->categoryLabel
                );
            }
        }

        $household = Money::fromCents($laborShares[TaxBenefitCategory::HOUSEHOLD_SERVICE->value] ?? 0);
        $craftsman = Money::fromCents($laborShares[TaxBenefitCategory::CRAFTSMAN_SERVICE->value] ?? 0);

        return new UnitStatementResult(
            $participant->participantKey,
            $participant->unitKey,
            $input->unit($participant->unitKey)->label ?? $participant->unitKey,
            $participant->label,
            $input->billingPeriod,
            $participant->period,
            $lines,
            $allocableTotal,
            $target,
            $actual,
            $assumed,
            $allocableTotal->minus($actual),
            $household,
            $craftsman,
            array_values(array_unique($assumptions)),
            $statementFindings
        );
    }

    /**
     * @param  list<CheckFinding>  $findings
     */
    private function checkServicePeriod(CostItemInput $item, DatePeriodRange $billingPeriod, array &$findings): void
    {
        if (! $item->servicePeriod instanceof DatePeriodRange) {
            $findings[] = CheckFinding::info(
                CheckCode::COST_WITHOUT_SERVICE_PERIOD,
                sprintf('Für die Position "%s" ist kein Leistungszeitraum erfasst.', $item->categoryLabel),
                ['costItemKey' => $item->costItemKey]
            );

            return;
        }

        if (! $billingPeriod->containsPeriod($item->servicePeriod)) {
            $findings[] = CheckFinding::warning(
                CheckCode::COST_OUTSIDE_BILLING_PERIOD,
                sprintf(
                    'Der Leistungszeitraum der Position "%s" (%s) liegt nicht vollständig im Abrechnungszeitraum '
                    .'(%s).',
                    $item->categoryLabel,
                    $item->servicePeriod->format(),
                    $billingPeriod->format()
                ),
                ['costItemKey' => $item->costItemKey]
            );
        }
    }
}
