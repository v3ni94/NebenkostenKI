<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\Reconciliation\Dto\HeatingMatrix;
use App\Application\Reconciliation\Dto\HeatingMatrixRow;
use App\Application\Reconciliation\Dto\HeatingSourceKind;
use App\Application\Reconciliation\Dto\MappingOutcome;
use App\Application\Reconciliation\Dto\MissingRequirement;
use App\Application\Reconciliation\Dto\ProposedCostItem;
use App\Application\Reconciliation\Support\ExtractedFieldBag;
use App\Domain\Calculation\Heating\Co2AllocationStatus;
use App\Domain\Calculation\Heating\ExternalHeatingReconciler;
use App\Domain\Calculation\Heating\ExternalHeatingStatementInput;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Enums\ApportionmentStatus;
use App\Enums\BillingMode;
use App\Enums\CostItemSource;
use App\Enums\DocumentType;
use App\Enums\Paragraph35aType;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\HeatingStatement;
use App\Models\Unit;
use Illuminate\Support\Carbon;

/**
 * Reconciliation-Matrix der Heizkosten nach Abschnitt 7.4.
 *
 * Quellen und vorgeschlagene Behandlung:
 *
 *  - Heizkostenposition der Hausgeldabrechnung: Vergleichssumme, sobald eine
 *    externe Abrechnung vorliegt, sonst Kostenquelle.
 *  - externe Einzelabrechnung: Mieteranteil beziehungsweise Direktzuordnung.
 *  - Brennstoffrechnung: nur im Vollobjektmodus, mit Dublettenpruefung.
 *  - manuell erfasst (Fall B): Direktzuordnung je Einheit. Die Plattform
 *    uebernimmt die vom Vermieter ermittelten Betraege unveraendert und
 *    rechnet sie nicht nach.
 *
 * Liegt eine externe Abrechnung vor, duerfen ihre Einzelbetraege nicht
 * zusaetzlich aus einer WEG-Summenposition angesetzt werden.
 *
 * Die Pruefsumme wird von der Domain gebildet
 * (App\Domain\Calculation\Heating\ExternalHeatingReconciler). Eine Abweichung
 * ueber der Toleranz aus config('smartabrechnen.tolerances.checksum_cent')
 * blockiert die automatische Finalisierung, bis der Nutzer sie erklaert oder
 * korrigiert.
 */
final class HeatingReconciler
{
    private const string CATEGORY_HEATING = 'HEIZUNG';

    public function __construct(
        private readonly ExternalHeatingReconciler $reconciler,
        private readonly CategoryResolver $categories,
    ) {}

    public static function toleranceCent(): int
    {
        $value = config('smartabrechnen.tolerances.checksum_cent');

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * @param  list<Document>  $documents  alle Dokumente des Abrechnungslaufs
     */
    public function matrix(BillingRun $billingRun, array $documents): HeatingMatrix
    {
        $rows = [];
        $missing = [];

        $external = $this->firstOfType($documents, DocumentType::HEIZKOSTENABRECHNUNG);
        $wegStatements = $this->allOfType($documents, DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL);
        $fuelInvoices = $this->allOfType($documents, DocumentType::ENERGIE_BRENNSTOFFRECHNUNG);

        $externalPresent = $external instanceof Document;
        $tolerance = self::toleranceCent();

        $externalTotal = null;
        $lineSum = null;
        $difference = null;
        $withinTolerance = true;
        $blocks = false;
        $blockingExplanation = null;

        if ($external instanceof Document) {
            $bag = ExtractedFieldBag::forDocument($external);
            $period = $this->periodLabel(
                $bag->date('abrechnungszeitraum_von'),
                $bag->date('abrechnungszeitraum_bis'),
                $billingRun
            );

            $amounts = [];

            foreach ($bag->listIndexes('einheiten') as $index) {
                $prefix = sprintf('einheiten[%d]', $index);
                $amount = $bag->integer($prefix.'.summe_cent');

                if ($amount === null) {
                    continue;
                }

                $label = $bag->text($prefix.'.einheitsbezeichnung', 120) ?? sprintf('Einheit %d', $index + 1);
                $key = sprintf('%s#%d', $label, $index);
                $amounts[$key] = Money::fromCents($amount);

                $rows[] = new HeatingMatrixRow(
                    HeatingSourceKind::EXTERNE_EINZELABRECHNUNG,
                    $bag->text('abrechnungsdienst', 190) ?? $this->sourceLabel($external),
                    $amount,
                    $label,
                    $this->periodLabel(
                        $bag->date($prefix.'.nutzungszeitraum_von'),
                        $bag->date($prefix.'.nutzungszeitraum_bis'),
                        $billingRun
                    ),
                    'Mieteranteil, Direktzuordnung',
                    true,
                    (string) $external->getKey(),
                );
            }

            $total = $bag->integer('gesamtkosten_summe_cent');

            if ($total === null) {
                $missing[] = new MissingRequirement(
                    'Gesamtbetrag der Heizkostenabrechnung',
                    sprintf(
                        'Aus %s ließ sich kein Gesamtbetrag auslesen. Ohne Gesamtbetrag ist die Prüfsumme der '
                        .'Einzelbeträge nicht möglich. Bitte tragen Sie den Gesamtbetrag nach.',
                        $this->sourceLabel($external)
                    ),
                    (string) $external->getKey(),
                );
            } elseif ($amounts === []) {
                $missing[] = new MissingRequirement(
                    'Einzelbeträge der Heizkostenabrechnung',
                    sprintf(
                        'Aus %s ließen sich keine Einzelbeträge je Einheit auslesen. Ohne Einzelbeträge werden '
                        .'keine Heizkosten zugeordnet.',
                        $this->sourceLabel($external)
                    ),
                    (string) $external->getKey(),
                );
            } else {
                $statement = new ExternalHeatingStatementInput(
                    $bag->text('abrechnungsdienst', 190) ?? 'Abrechnungsdienst',
                    $this->period($bag, $billingRun),
                    Money::fromCents($total),
                    $amounts,
                    $this->co2Status($bag->text('co2_kostenaufteilung_status', 40)),
                );

                $result = $this->reconciler->reconcile($statement, Money::fromCents($tolerance));

                $externalTotal = $total;
                $lineSum = $result->sumOfParticipantAmounts->cents;
                $difference = $result->difference->cents;
                $withinTolerance = $result->withinTolerance;
                $blocks = $result->blocksFinalization();

                if ($blocks) {
                    $blockingExplanation = sprintf(
                        'Die Einzelbeträge der Heizkostenabrechnung ergeben %s, ausgewiesen ist ein Gesamtbetrag '
                        .'von %s. Die Abweichung von %s liegt über der zulässigen Toleranz von %s. '
                        .'Bitte erklären oder korrigieren Sie die Abweichung. Solange sie offen ist, kann die '
                        .'Abrechnung nicht abgeschlossen werden.',
                        Money::fromCents($lineSum)->format(),
                        Money::fromCents($externalTotal)->format(),
                        $result->difference->absolute()->format(),
                        Money::fromCents($tolerance)->format()
                    );
                }

                array_unshift($rows, new HeatingMatrixRow(
                    HeatingSourceKind::EXTERNE_EINZELABRECHNUNG,
                    $bag->text('abrechnungsdienst', 190) ?? $this->sourceLabel($external),
                    $total,
                    'Gesamtbetrag der Abrechnung',
                    $period,
                    'Prüfsumme gegen die Einzelbeträge',
                    false,
                    (string) $external->getKey(),
                ));
            }
        }

        foreach ($wegStatements as $document) {
            $bag = ExtractedFieldBag::forDocument($document);

            foreach ([
                ['heizkosten_anteil_einheit_cent', 'Heizkosten'],
                ['warmwasserkosten_anteil_einheit_cent', 'Warmwasserkosten'],
            ] as [$path, $label]) {
                $amount = $bag->integer($path);

                if ($amount === null) {
                    continue;
                }

                $rows[] = new HeatingMatrixRow(
                    HeatingSourceKind::HAUSGELD_HEIZKOSTEN,
                    sprintf('%s, %s', $this->sourceLabel($document), $label),
                    $amount,
                    $bag->text('einheitsbezeichnung', 120) ?? 'Einheit',
                    $this->periodLabel(
                        $bag->date('abrechnungszeitraum_von'),
                        $bag->date('abrechnungszeitraum_bis'),
                        $billingRun
                    ),
                    $externalPresent
                        ? 'Nur Vergleichssumme, kein zusätzlicher Ansatz'
                        : 'Kostenquelle für die Umlage',
                    ! $externalPresent,
                    (string) $document->getKey(),
                );
            }
        }

        $fullProperty = $billingRun->getAttribute('mode') === BillingMode::FULL_PROPERTY;

        foreach ($fuelInvoices as $document) {
            $bag = ExtractedFieldBag::forDocument($document);
            $amount = $bag->integer('gesamtbetrag_brutto_cent');

            $rows[] = new HeatingMatrixRow(
                HeatingSourceKind::BRENNSTOFFRECHNUNG,
                $bag->text('aussteller', 190) ?? $this->sourceLabel($document),
                $amount,
                'Objekt',
                $this->periodLabel(
                    $bag->date('leistungszeitraum_von'),
                    $bag->date('leistungszeitraum_bis'),
                    $billingRun
                ),
                $fullProperty
                    ? ($externalPresent
                        ? 'Vollobjektabrechnung, Dublettenprüfung gegen die externe Abrechnung'
                        : 'Vollobjektabrechnung, Dublettenprüfung')
                    : 'Nicht angesetzt, nur in der vollständigen Objektabrechnung vorgesehen',
                $fullProperty && ! $externalPresent,
                (string) $document->getKey(),
            );
        }

        $manual = $this->manualRows($billingRun, $externalPresent);

        foreach ($manual['rows'] as $row) {
            $rows[] = $row;
        }

        return new HeatingMatrix(
            $rows,
            $missing,
            $externalPresent,
            $externalTotal,
            $lineSum,
            $difference,
            $tolerance,
            $withinTolerance,
            $blocks,
            $blockingExplanation,
            $manual['present'],
            $manual['origin'],
            $manual['conflict'],
            $manual['explanation'],
        );
    }

    /**
     * Quelle "manuell erfasst" nach Abschnitt 7.4, Fall B.
     *
     * Behandlung ist die Direktzuordnung je Einheit. Die Plattform rechnet die
     * erfassten Betraege nicht nach. Liegt zusaetzlich eine externe Abrechnung
     * oder eine WEG-Summenposition vor, wird nicht addiert; angesetzt wird nur
     * die Quelle, fuer die sich der Anwender entschieden hat.
     *
     * @return array{rows: list<HeatingMatrixRow>, present: bool, origin: string|null, conflict: bool, explanation: string|null}
     */
    private function manualRows(BillingRun $billingRun, bool $externalPresent): array
    {
        $statements = HeatingStatement::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('manual_entry', true)
            ->with('lines')
            ->get();

        $rows = [];
        $origin = null;
        $present = false;
        $conflict = false;
        $explanation = null;

        foreach ($statements as $statement) {
            $present = true;
            $decision = $statement->getAttribute('manual_source_decision');
            $decision = is_string($decision) ? $decision : null;
            $applied = ! $externalPresent || $decision === 'MANUELL';

            if ($externalPresent) {
                $conflict = true;
                $explanation = $decision === null
                    ? 'Für den Zeitraum liegen manuell erfasste Heizkosten und eine weitere Heizkostenquelle vor. '
                        .'Die Beträge werden nicht addiert. Bitte entscheiden Sie, welche Quelle gilt; solange keine '
                        .'Entscheidung vorliegt, wird die manuelle Erfassung nicht angesetzt.'
                    : sprintf(
                        'Für den Zeitraum liegen mehrere Heizkostenquellen vor. Die Beträge werden nicht addiert. '
                        .'Ihre Entscheidung: %s.',
                        $decision === 'MANUELL'
                            ? 'Es gelten die manuell erfassten Beträge'
                            : 'Es gilt die externe Abrechnung beziehungsweise die WEG-Position'
                    );
            }

            $originValue = $statement->getAttribute('calculation_origin');

            if (is_string($originValue) && $originValue !== '') {
                $origin = $originValue;
            }

            $start = $statement->getAttribute('period_start');
            $end = $statement->getAttribute('period_end');

            $periodLabel = $this->periodLabel(
                $start instanceof Carbon ? $start : null,
                $end instanceof Carbon ? $end : null,
                $billingRun
            );

            $byUnit = [];

            foreach ($statement->lines as $line) {
                $label = $line->getAttribute('unit_label');
                $label = is_string($label) && $label !== '' ? $label : 'Einheit';
                $amount = $line->getAttribute('share_total_cent');
                $byUnit[$label] = ($byUnit[$label] ?? 0) + (is_numeric($amount) ? (int) $amount : 0);
            }

            foreach ($byUnit as $label => $amount) {
                $rows[] = new HeatingMatrixRow(
                    HeatingSourceKind::MANUELL_ERFASST,
                    'Vom Vermieter selbst ermittelt, ohne Nachrechnung durch die Plattform',
                    $amount,
                    (string) $label,
                    $periodLabel,
                    $applied
                        ? 'Direktzuordnung'
                        : 'Direktzuordnung, derzeit nicht angesetzt, weil eine weitere Quelle vorliegt',
                    $applied,
                    null,
                );
            }
        }

        return [
            'rows' => $rows,
            'present' => $present,
            'origin' => $origin,
            'conflict' => $conflict,
            'explanation' => $explanation,
        ];
    }

    /**
     * Liegt eine externe Heizkostenabrechnung vor? Dann darf die
     * WEG-Summenposition nicht zusaetzlich angesetzt werden.
     *
     * @param  list<Document>  $documents
     */
    public function externalStatementPresent(array $documents): bool
    {
        return $this->firstOfType($documents, DocumentType::HEIZKOSTENABRECHNUNG) instanceof Document;
    }

    /**
     * Vorgeschlagene Kostenpositionen aus der externen Heizkostenabrechnung
     * (Fall A): je ausgelesenem Einheitenanteil eine Heizkostenposition mit
     * dem Mieteranteil. Ohne diese Uebernahme stuenden die Heizkosten in der
     * Mieterabrechnung mit null, obwohl die WEG-Summenposition wegen der
     * externen Abrechnung ausgeschlossen wird.
     *
     * Die Einheit wird ueber die ausgelesene Bezeichnung zugeordnet; bei
     * genau einer Einheit und genau einem Anteil ist die Zuordnung eindeutig.
     * Laesst sich keine Einheit eindeutig bestimmen, bleibt die Position
     * pruefpflichtig und es entsteht eine Pruefaufgabe zur Zuordnung. Es wird
     * nichts geraten und nichts automatisch bestaetigt.
     */
    public function proposals(BillingRun $billingRun, Document $external, ?ExtractedFieldBag $bag = null): MappingOutcome
    {
        $bag ??= ExtractedFieldBag::forDocument($external);

        $documentId = (string) $external->getKey();
        $sourceLabel = $bag->text('abrechnungsdienst', 190) ?? $this->sourceLabel($external);
        $period = $this->period($bag, $billingRun);
        $units = $this->unitsByLabel($billingRun);
        $indexes = $bag->listIndexes('einheiten');

        $category = $this->categories->byCode($billingRun, self::CATEGORY_HEATING);
        $status = $category?->getAttribute('apportionment_status');
        $apportionment = $status instanceof ApportionmentStatus ? $status : ApportionmentStatus::PRUEFPFLICHTIG;

        $proposals = [];
        $missing = [];

        foreach ($indexes as $index) {
            $prefix = sprintf('einheiten[%d]', $index);
            $amount = $bag->integer($prefix.'.summe_cent');

            if ($amount === null) {
                continue;
            }

            $unitLabel = $bag->text($prefix.'.einheitsbezeichnung', 120);
            $unitId = $this->matchUnit($unitLabel, $units, count($indexes));

            $description = sprintf(
                'Heizkosten laut Heizkostenabrechnung %s, %s',
                $sourceLabel,
                $unitLabel ?? sprintf('Einheit %d', $index + 1)
            );

            if ($unitId === null) {
                $missing[] = new MissingRequirement(
                    'Zuordnung der Heizkosten zur Einheit',
                    sprintf(
                        'Der Anteil "%s" über %s aus %s ließ sich keiner Einheit des Objekts eindeutig zuordnen. '
                        .'Bitte ordnen Sie die Position in der Kostenprüfung der richtigen Einheit zu. Ohne '
                        .'Zuordnung würde der Betrag nach dem Schlüssel der Kostenart verteilt.',
                        $unitLabel ?? sprintf('Einheit %d', $index + 1),
                        Money::fromCents($amount)->format(),
                        $sourceLabel
                    ),
                    $documentId,
                );
            }

            $start = $bag->date($prefix.'.nutzungszeitraum_von');
            $end = $bag->date($prefix.'.nutzungszeitraum_bis');

            $proposals[] = new ProposedCostItem(
                sprintf('%s#heizung-%d', $documentId, $index),
                mb_substr($description, 0, 190),
                $amount,
                $documentId,
                $sourceLabel,
                $bag->text('abrechnungsnummer', 80),
                null,
                $start instanceof Carbon && $end instanceof Carbon && $start <= $end ? $start : Carbon::instance($period->start),
                $start instanceof Carbon && $end instanceof Carbon && $start <= $end ? $end : Carbon::instance($period->end),
                self::CATEGORY_HEATING,
                $unitId === null ? ApportionmentStatus::PRUEFPFLICHTIG : $apportionment,
                false,
                Paragraph35aType::NONE,
                null,
                $bag->lowestConfidence($prefix.'.summe_cent', $prefix.'.einheitsbezeichnung'),
                $bag->firstPage($prefix.'.summe_cent', $prefix.'.einheitsbezeichnung'),
                $bag->firstExcerpt($prefix.'.summe_cent', $prefix.'.einheitsbezeichnung'),
                true,
                false,
                $unitId,
                CostItemSource::KI_EXTRAKTION,
                $this->sourceLabel($external),
            );
        }

        return new MappingOutcome($proposals, $missing);
    }

    /**
     * Einheiten des Objekts nach normalisierter Bezeichnung.
     *
     * @return array<string, list<string>> Bezeichnung => Kennungen
     */
    private function unitsByLabel(BillingRun $billingRun): array
    {
        $units = [];

        $rows = Unit::query()
            ->where('property_id', $billingRun->getAttribute('property_id'))
            ->orderBy('label')
            ->get();

        foreach ($rows as $unit) {
            $label = $this->normalizeLabel((string) $unit->getAttribute('label'));
            $units[$label][] = (string) $unit->getKey();
        }

        return $units;
    }

    /**
     * Eindeutige Einheit zu einer ausgelesenen Bezeichnung. Bei genau einer
     * Einheit im Objekt und genau einem Anteil in der Abrechnung ist die
     * Zuordnung ohne Bezeichnung eindeutig.
     *
     * @param  array<string, list<string>>  $units
     */
    private function matchUnit(?string $label, array $units, int $shareCount): ?string
    {
        $allIds = array_merge(...array_values($units));

        if (count($allIds) === 1 && $shareCount === 1) {
            return $allIds[0];
        }

        if ($label === null) {
            return null;
        }

        $candidates = $units[$this->normalizeLabel($label)] ?? [];

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function normalizeLabel(string $label): string
    {
        $normalized = mb_strtolower(trim($label));

        return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    }

    private function co2Status(?string $value): Co2AllocationStatus
    {
        return match ($value) {
            'ENTHALTEN' => Co2AllocationStatus::INCLUDED,
            'NICHT_ENTHALTEN' => Co2AllocationStatus::EXCLUDED,
            default => Co2AllocationStatus::UNKNOWN,
        };
    }

    /**
     * @param  list<Document>  $documents
     * @return list<Document>
     */
    private function allOfType(array $documents, DocumentType $type): array
    {
        return array_values(array_filter(
            $documents,
            static fn (Document $document): bool => $document->getAttribute('document_type') === $type
        ));
    }

    /**
     * @param  list<Document>  $documents
     */
    private function firstOfType(array $documents, DocumentType $type): ?Document
    {
        $matches = $this->allOfType($documents, $type);

        return $matches === [] ? null : $matches[0];
    }

    private function period(ExtractedFieldBag $bag, BillingRun $billingRun): DatePeriodRange
    {
        $start = $bag->date('abrechnungszeitraum_von');
        $end = $bag->date('abrechnungszeitraum_bis');

        if ($start instanceof Carbon && $end instanceof Carbon && $start <= $end) {
            return new DatePeriodRange($start->toDateTimeImmutable(), $end->toDateTimeImmutable());
        }

        $runStart = $billingRun->getAttribute('period_start');
        $runEnd = $billingRun->getAttribute('period_end');

        return new DatePeriodRange(
            $runStart instanceof Carbon ? $runStart->toDateTimeImmutable() : Carbon::now()->startOfYear()->toDateTimeImmutable(),
            $runEnd instanceof Carbon ? $runEnd->toDateTimeImmutable() : Carbon::now()->endOfYear()->toDateTimeImmutable(),
        );
    }

    private function periodLabel(?Carbon $start, ?Carbon $end, BillingRun $billingRun): string
    {
        if ($start instanceof Carbon && $end instanceof Carbon) {
            return sprintf('%s bis %s', $start->format('d.m.Y'), $end->format('d.m.Y'));
        }

        $runStart = $billingRun->getAttribute('period_start');
        $runEnd = $billingRun->getAttribute('period_end');

        if ($runStart instanceof Carbon && $runEnd instanceof Carbon) {
            return sprintf('%s bis %s', $runStart->format('d.m.Y'), $runEnd->format('d.m.Y'));
        }

        return 'Zeitraum nicht erkannt';
    }

    private function sourceLabel(Document $document): string
    {
        $label = $document->getAttribute('source_label');

        return is_string($label) && $label !== '' ? $label : 'der Unterlage';
    }
}
