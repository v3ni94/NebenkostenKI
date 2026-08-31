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
     */
    public function build(
        array $records,
        array $participantDaysByUnit,
        string $measurementUnit = '',
        array $substituteDistributionConfirmedUnits = [],
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

        foreach ($participantDaysByUnit as $unitKey => $participantDays) {
            $unitKey = (string) $unitKey;
            $participantKeys = array_map(
                static fn (int|string $key): string => (string) $key,
                array_keys($participantDays)
            );

            if (isset($occupancyRecords[$unitKey])) {
                foreach ($participantKeys as $participantKey) {
                    if (! isset($occupancyRecords[$unitKey][$participantKey])) {
                        throw MissingInterimReadingException::forUnit($unitKey, count($participantKeys));
                    }

                    $values[$participantKey] = $occupancyRecords[$unitKey][$participantKey];
                }

                continue;
            }

            $unitTotal = $unitRecords[$unitKey] ?? BigDecimal::zero();

            if (count($participantKeys) === 1) {
                $values[$participantKeys[0]] = $unitTotal;

                continue;
            }

            if (! in_array($unitKey, $substituteDistributionConfirmedUnits, true)) {
                throw MissingInterimReadingException::forUnit($unitKey, count($participantKeys));
            }

            foreach ($this->splitByDays($unitTotal, $participantDays) as $participantKey => $value) {
                $values[$participantKey] = $value;
                $substituteParticipants[] = $participantKey;
            }
        }

        return ConsumptionKey::create($values, $measurementUnit, $substituteParticipants);
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
