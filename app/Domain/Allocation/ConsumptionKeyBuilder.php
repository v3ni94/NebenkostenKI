<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Calculation\Rounding\LargestRemainderDistributor;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Erzeugt einen Verbrauchsschlüssel aus Ablesewerten.
 *
 * Verbindliche Regel (Pflichtenheft Abschnitt 11.2): Ein Verbrauch wird bei
 * Nutzerwechsel nur anhand einer Zwischenablesung geteilt. Fehlt sie, gibt es
 * KEINE stille Schätzung:
 *
 * - ohne Bestätigung wird MissingInterimReadingException geworfen,
 * - mit ausdrücklich bestätigter Ersatzverteilung
 *   (substituteDistributionConfirmed) wird taggenau aufgeteilt und jeder
 *   betroffene Nutzungszeitraum im Ergebnis gekennzeichnet, damit das PDF den
 *   Hinweis druckt.
 *
 * Beteiligte des Eigentümers (erfasster Leerstand) verlangen keine
 * Zwischenablesung: Liegen Ablesewerte je Mietverhältnis vor, erhält ein
 * Leerstand ohne eigenen Ablesewert den Rest des Einheitenverbrauchs, sofern
 * ein Einheitenwert erfasst ist, sonst den Verbrauch null. Eine Einheit ganz
 * ohne Nutzungszeitraum (zum Beispiel eigengenutzt) bleibt ohne Beteiligten;
 * ihr Einheitenverbrauch geht nur in den Nenner ein, damit der Anteil beim
 * Eigentümer verbleibt und nicht auf die Mieter verschoben wird.
 *
 * Die Ersatzverteilung erfolgt mit dem Largest-Remainder-Verfahren auf der
 * angegebenen Nachkommastelle, damit die Summe der Teilverbräuche exakt dem
 * Gesamtverbrauch der Einheit entspricht.
 */
final class ConsumptionKeyBuilder
{
    private const int SUBSTITUTE_SCALE = 3;

    private LargestRemainderDistributor $distributor;

    public function __construct(?LargestRemainderDistributor $distributor = null)
    {
        $this->distributor = $distributor ?? new LargestRemainderDistributor;
    }

    /**
     * @param  list<ConsumptionRecord>  $records
     * @param  array<string, array<string, int>>  $participantDaysByUnit  Einheit => (Nutzungszeitraum => Tage)
     * @param  list<string>  $substituteDistributionConfirmedUnits  Einheiten mit bestätigter Ersatzverteilung
     * @param  list<string>  $ownerParticipantKeys  Nutzungszeiträume des Eigentümers (Leerstand), die keine Ablesung verlangen
     */
    public function build(
        array $records,
        array $participantDaysByUnit,
        string $measurementUnit = '',
        array $substituteDistributionConfirmedUnits = [],
        array $ownerParticipantKeys = [],
    ): ConsumptionKey {
        /** @var array<string, array<string, BigDecimal>> $occupancyRecords */
        $occupancyRecords = [];
        /** @var array<string, BigDecimal> $unitRecords */
        $unitRecords = [];

        foreach ($records as $record) {
            if ($record->participantKey !== null) {
                $occupancyRecords[$record->unitKey][$record->participantKey] = $record->value;

                continue;
            }

            $unitRecords[$record->unitKey] = $record->value;
        }

        $values = [];
        $substituteParticipants = [];
        $unassigned = BigDecimal::zero();

        foreach ($participantDaysByUnit as $unitKey => $participantDays) {
            $unitKey = (string) $unitKey;
            $participantKeys = array_map(
                static fn (int|string $key): string => (string) $key,
                array_keys($participantDays)
            );
            $unitTotal = $unitRecords[$unitKey] ?? null;

            if ($participantKeys === []) {
                // Einheit ohne Nutzungszeitraum: kein Beteiligter. Der Wert
                // bleibt beim Eigentümer und erhöht nur den Nenner.
                if ($unitTotal instanceof BigDecimal) {
                    $unassigned = $unassigned->plus($unitTotal);
                }

                continue;
            }

            if (isset($occupancyRecords[$unitKey])) {
                $withoutReading = [];
                $assigned = BigDecimal::zero();

                foreach ($participantKeys as $participantKey) {
                    if (isset($occupancyRecords[$unitKey][$participantKey])) {
                        $values[$participantKey] = $occupancyRecords[$unitKey][$participantKey];
                        $assigned = $assigned->plus($occupancyRecords[$unitKey][$participantKey]);

                        continue;
                    }

                    if (in_array($participantKey, $ownerParticipantKeys, true)) {
                        $withoutReading[] = $participantKey;

                        continue;
                    }

                    throw MissingInterimReadingException::forUnit($unitKey, count($participantKeys));
                }

                if ($withoutReading !== []) {
                    $rest = $unitTotal instanceof BigDecimal && $unitTotal->isGreaterThan($assigned)
                        ? $unitTotal->minus($assigned)
                        : BigDecimal::zero();

                    foreach ($this->ownerShares($rest, $withoutReading, $participantDays) as $participantKey => $value) {
                        $values[$participantKey] = $value;
                    }
                }

                continue;
            }

            $total = $unitTotal ?? BigDecimal::zero();

            if (count($participantKeys) === 1) {
                $values[$participantKeys[0]] = $total;

                continue;
            }

            if (! in_array($unitKey, $substituteDistributionConfirmedUnits, true)) {
                throw MissingInterimReadingException::forUnit($unitKey, count($participantKeys));
            }

            foreach ($this->splitByDays($total, $participantDays) as $participantKey => $value) {
                $values[$participantKey] = $value;
                $substituteParticipants[] = $participantKey;
            }
        }

        $denominator = null;

        if (! $unassigned->isZero()) {
            $sum = BigDecimal::zero();

            foreach ($values as $value) {
                $sum = $sum->plus($value);
            }

            $denominator = $sum->plus($unassigned);
        }

        return ConsumptionKey::create($values, $measurementUnit, $substituteParticipants, $denominator);
    }

    /**
     * Rest des Einheitenverbrauchs für Beteiligte des Eigentümers ohne eigene
     * Ablesung. Ein einzelner Leerstand erhält den Rest vollständig, mehrere
     * Leerstände teilen ihn taggenau; alle Anteile verbleiben beim Eigentümer.
     *
     * @param  list<string>  $participantKeys
     * @param  array<string, int>  $participantDays
     * @return array<string, BigDecimal>
     */
    private function ownerShares(BigDecimal $rest, array $participantKeys, array $participantDays): array
    {
        if (count($participantKeys) === 1 || $rest->isZero()) {
            $shares = [];

            foreach ($participantKeys as $index => $participantKey) {
                $shares[$participantKey] = $index === 0 ? $rest : BigDecimal::zero();
            }

            return $shares;
        }

        $days = [];

        foreach ($participantKeys as $participantKey) {
            $days[$participantKey] = $participantDays[$participantKey] ?? 0;
        }

        return $this->splitByDays($rest, $days);
    }

    /**
     * Taggenaue Ersatzverteilung eines Einheitenverbrauchs.
     *
     * @param  array<string, int>  $participantDays
     * @return array<string, BigDecimal>
     */
    private function splitByDays(BigDecimal $unitTotal, array $participantDays): array
    {
        $scaled = $unitTotal->toScale(self::SUBSTITUTE_SCALE, RoundingMode::HALF_UP)->getUnscaledValue()->toInt();

        $weights = [];

        foreach ($participantDays as $participantKey => $days) {
            $weights[(string) $participantKey] = BigRational::nd(max($days, 0), 1);
        }

        $distribution = $this->distributor->distributeProportionally($scaled, $weights);

        $result = [];

        foreach ($distribution->amounts as $participantKey => $amount) {
            $result[(string) $participantKey] = BigDecimal::ofUnscaledValue($amount, self::SUBSTITUTE_SCALE);
        }

        return $result;
    }
}
