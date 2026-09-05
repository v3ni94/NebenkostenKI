<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\Reconciliation\Dto\MappingOutcome;
use App\Application\Reconciliation\Dto\MissingRequirement;
use App\Application\Reconciliation\Dto\PropertyTaxOutcome;
use App\Application\Reconciliation\Dto\ProposedCostItem;
use App\Application\Reconciliation\Support\ExtractedFieldBag;
use App\Domain\Calculation\Weg\HausgeldStatementInput;
use App\Domain\Calculation\Weg\PropertyTaxInput;
use App\Domain\Calculation\Weg\PropertyTaxMerger;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Enums\ApportionmentStatus;
use App\Enums\CostItemSource;
use App\Enums\DocumentType;
use App\Enums\Paragraph35aType;
use App\Models\BillingRun;
use App\Models\Document;
use Illuminate\Support\Carbon;

/**
 * Grundsteuer aus dem Bescheid uebernehmen, aber nur wenn sie nicht bereits
 * erfasst ist (Abschnitt 7.3).
 *
 * Die Entscheidungslogik liegt in der Domain
 * (App\Domain\Calculation\Weg\PropertyTaxMerger). Diese Klasse baut die
 * Eingabe aus den ausgelesenen Inhaltsdaten und uebersetzt das Ergebnis.
 *
 * VERBINDLICH
 *
 *  1. Bei moeglicher Dublette wird NICHT addiert. Es entsteht eine
 *     Pruefaufgabe.
 *  2. Ist die Grundsteuer eindeutig separat und der Einheit direkt zugeordnet,
 *     wird sie als direkte Betriebskostenposition vorgeschlagen.
 *  3. Teilzeitraeume und Eigentumswechsel werden nicht geraten. Sie werden dem
 *     Nutzer zur Bestaetigung vorgelegt. Dasselbe gilt, wenn weder Zeitraum
 *     noch Steuerjahr ausgelesen wurden: ein unbekannter Zeitraum wird nicht
 *     dem Abrechnungszeitraum gleichgesetzt.
 */
final class PropertyTaxReconciler
{
    public function __construct(
        private readonly CategoryResolver $categories,
        private readonly PropertyTaxMerger $merger,
    ) {}

    public static function supports(Document $document): bool
    {
        return $document->getAttribute('document_type') === DocumentType::GRUNDSTEUERBESCHEID;
    }

    /**
     * @param  list<string>  $existingCategoryCodes  bereits erfasste Kategorien anderer Kostenquellen
     */
    public function reconcile(
        BillingRun $billingRun,
        Document $document,
        HausgeldStatementInput $statement,
        array $existingCategoryCodes = [],
        bool $periodConfirmed = false,
        ?ExtractedFieldBag $bag = null,
    ): PropertyTaxOutcome {
        $bag ??= ExtractedFieldBag::forDocument($document);

        $documentId = (string) $document->getKey();
        $sourceLabel = $this->sourceLabel($document);

        $amount = $bag->integer('jahresbetrag_cent');

        if ($amount === null) {
            return new PropertyTaxOutcome(
                new MappingOutcome([], [new MissingRequirement(
                    'Grundsteuer-Jahresbetrag',
                    sprintf(
                        'Aus %s ließ sich kein Jahresbetrag auslesen. Ohne Betrag wird die Grundsteuer nicht '
                        .'angesetzt. Bitte tragen Sie den Jahresbetrag nach.',
                        $sourceLabel
                    ),
                    $documentId,
                )]),
                false,
                false,
                'Der Jahresbetrag der Grundsteuer fehlt. Es wurde keine Position angesetzt.',
            );
        }

        $period = $this->period($bag);
        $fileReference = $bag->text('aktenzeichen', 80);
        $unitLabel = $bag->text('einheitsbezeichnung', 120);
        $partialPeriod = $bag->boolean('betrifft_teilzeitraum') === true
            || $bag->boolean('eigentumswechsel_erwaehnt') === true;

        // Ohne ausgelesenen Zeitraum und ohne Steuerjahr wird der Zeitraum
        // nicht mit dem Abrechnungszeitraum gleichgesetzt. Ohne
        // ausdrueckliche Bestaetigung entsteht keine Position.
        if (! $period instanceof DatePeriodRange && ! $periodConfirmed) {
            $explanation = sprintf(
                'Aus %s ließ sich weder der Zeitraum noch das Steuerjahr auslesen. Der Bescheid wird nicht '
                .'ungeprüft dem Abrechnungszeitraum zugeordnet. Bitte bestätigen Sie Zeitraum und Betrag.',
                $sourceLabel
            );

            return new PropertyTaxOutcome(
                new MappingOutcome([], [new MissingRequirement(
                    'Zeitraum des Grundsteuerbescheids',
                    $explanation,
                    $documentId,
                )]),
                false,
                false,
                $explanation,
                $amount,
                $fileReference,
                'nicht erkannt',
                true,
            );
        }

        $period ??= $this->billingPeriod($billingRun);

        // Ein Teilzeitraum oder ein Eigentumswechsel wird nicht geraten. Ohne
        // ausdrueckliche Bestaetigung entsteht keine Position.
        if ($partialPeriod && ! $periodConfirmed) {
            $explanation = sprintf(
                'Der Bescheid aus %s nennt einen Teilzeitraum oder einen Eigentumswechsel. Der anzusetzende '
                .'Betrag wird nicht geschätzt. Bitte bestätigen Sie den Zeitraum und den Betrag.',
                $sourceLabel
            );

            return new PropertyTaxOutcome(
                new MappingOutcome([], [new MissingRequirement(
                    'Bestätigung des Grundsteuerzeitraums',
                    $explanation,
                    $documentId,
                )]),
                false,
                false,
                $explanation,
                $amount,
                $fileReference,
                $period->format(),
                true,
            );
        }

        $input = new PropertyTaxInput(
            $unitLabel ?? 'Einheit',
            Money::fromCents($amount),
            $period,
            $unitLabel !== null,
            $periodConfirmed,
            $fileReference,
        );

        $result = $this->merger->merge(
            $input,
            $statement,
            $this->billingPeriod($billingRun),
            'einheit-direkt',
            $existingCategoryCodes,
        );

        $missing = [];
        $proposals = [];
        $explanation = '';

        if ($result->possibleDuplicate) {
            $explanation = sprintf(
                'Die Grundsteuer über %s wurde nicht zusätzlich angesetzt, weil sie bereits in einer anderen '
                .'Kostenquelle enthalten ist. Bitte entscheiden Sie, welche Quelle maßgeblich ist. '
                .'Es wird nichts addiert.',
                Money::fromCents($amount)->format()
            );
        } elseif (! $result->added) {
            $explanation = $unitLabel === null
                ? sprintf(
                    'Der Grundsteuerbescheid aus %s ist nicht eindeutig einer Einheit zugeordnet. Eine Übernahme '
                    .'ist erst nach Ihrer Zuordnung und Bestätigung möglich.',
                    $sourceLabel
                )
                : sprintf(
                    'Der Zeitraum des Grundsteuerbescheids (%s) entspricht nicht dem Abrechnungszeitraum (%s). '
                    .'Teilzeiträume und Eigentumswechsel werden nicht geschätzt. Bitte bestätigen Sie den '
                    .'anzusetzenden Betrag.',
                    $period->format(),
                    $this->billingPeriod($billingRun)->format()
                );

            $missing[] = new MissingRequirement(
                'Bestätigung der Grundsteuer',
                $explanation,
                $documentId,
            );
        } else {
            $explanation = sprintf(
                'Die Grundsteuer über %s ist in keiner anderen Kostenquelle enthalten und wird als eigene '
                .'Betriebskostenposition vorgeschlagen.',
                Money::fromCents($amount)->format()
            );

            $category = $this->categories->byCode($billingRun, PropertyTaxMerger::CATEGORY_KEY);
            $status = $category?->getAttribute('apportionment_status');

            $proposals[] = new ProposedCostItem(
                sprintf('%s#grundsteuer', $documentId),
                $fileReference === null
                    ? 'Grundsteuer'
                    : mb_substr(sprintf('Grundsteuer, Aktenzeichen %s', $fileReference), 0, 190),
                $amount,
                $documentId,
                $bag->text('behoerde', 190),
                $fileReference,
                $bag->date('bescheiddatum'),
                Carbon::instance($period->start),
                Carbon::instance($period->end),
                PropertyTaxMerger::CATEGORY_KEY,
                $status instanceof ApportionmentStatus ? $status : ApportionmentStatus::PRUEFPFLICHTIG,
                false,
                Paragraph35aType::NONE,
                null,
                $bag->lowestConfidence('jahresbetrag_cent', 'aktenzeichen'),
                $bag->firstPage('jahresbetrag_cent', 'aktenzeichen'),
                $bag->firstExcerpt('jahresbetrag_cent', 'aktenzeichen'),
                false,
                false,
                null,
                CostItemSource::KI_EXTRAKTION,
                $sourceLabel,
            );
        }

        return new PropertyTaxOutcome(
            new MappingOutcome($proposals, $missing),
            $result->added,
            $result->possibleDuplicate,
            $explanation,
            $amount,
            $fileReference,
            $period->format(),
            ! $result->added && ! $result->possibleDuplicate,
        );
    }

    /**
     * Ausgelesener Zeitraum des Bescheids. Fehlen Zeitraum und Steuerjahr,
     * bleibt der Zeitraum unbekannt; er wird nicht geraten.
     */
    private function period(ExtractedFieldBag $bag): ?DatePeriodRange
    {
        $start = $bag->date('zeitraum_von');
        $end = $bag->date('zeitraum_bis');

        if ($start instanceof Carbon && $end instanceof Carbon && $start <= $end) {
            return new DatePeriodRange($start->toDateTimeImmutable(), $end->toDateTimeImmutable());
        }

        $year = $bag->integer('steuerjahr');

        if ($year !== null && $year >= 1900 && $year <= 2100) {
            return DatePeriodRange::calendarYear($year);
        }

        return null;
    }

    private function billingPeriod(BillingRun $billingRun): DatePeriodRange
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

        return is_string($label) && $label !== '' ? $label : 'dem Grundsteuerbescheid';
    }
}
