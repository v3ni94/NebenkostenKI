<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\Reconciliation\Dto\HeatingMatrix;
use App\Application\Reconciliation\Dto\HeatingMatrixRow;
use App\Application\Reconciliation\Dto\HeatingSourceKind;
use App\Application\Reconciliation\Dto\MissingRequirement;
use App\Application\Reconciliation\Support\ExtractedFieldBag;
use App\Domain\Calculation\Heating\Co2AllocationStatus;
use App\Domain\Calculation\Heating\ExternalHeatingReconciler;
use App\Domain\Calculation\Heating\ExternalHeatingStatementInput;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Enums\BillingMode;
use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Models\Document;
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
    public function __construct(private readonly ExternalHeatingReconciler $reconciler) {}

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

        return new HeatingMatrix(
            array_values($rows),
            array_values($missing),
            $externalPresent,
            $externalTotal,
            $lineSum,
            $difference,
            $tolerance,
            $withinTolerance,
            $blocks,
            $blockingExplanation,
        );
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
