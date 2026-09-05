<?php

declare(strict_types=1);

namespace App\Domain\Period;

/**
 * Prüfung der Abdeckung eines Rahmenzeitraums durch Teilzeiträume.
 *
 * Wird für Mietzeiträume und Leerstände einer Einheit verwendet:
 * - Lücken werden dem Eigentümer zugerechnet (Leerstand),
 * - Überschneidungen sind ein struktureller Eingabefehler.
 *
 * Alle Zeiträume haben inklusiven Start- und Endtag
 * (siehe App\Domain\Period\DatePeriodRange).
 */
final class PeriodCoverage
{
    /**
     * Ermittelt die nicht abgedeckten Teilzeiträume innerhalb des Rahmens.
     *
     * @param  list<DatePeriodRange>  $periods
     * @return list<DatePeriodRange>
     */
    public static function gapsWithin(DatePeriodRange $frame, array $periods): array
    {
        $clipped = [];

        foreach ($periods as $period) {
            $intersection = $frame->intersect($period);

            if ($intersection instanceof DatePeriodRange) {
                $clipped[] = $intersection;
            }
        }

        usort($clipped, static fn (DatePeriodRange $a, DatePeriodRange $b): int => $a->startIso() <=> $b->startIso());

        $gaps = [];
        $cursor = $frame->start;

        foreach ($clipped as $period) {
            if ($period->start > $cursor) {
                $gaps[] = new DatePeriodRange($cursor, $period->start->modify('-1 day'));
            }

            if ($period->end >= $cursor) {
                $cursor = $period->end->modify('+1 day');
            }

            if ($cursor > $frame->end) {
                break;
            }
        }

        if ($cursor <= $frame->end) {
            $gaps[] = new DatePeriodRange($cursor, $frame->end);
        }

        return $gaps;
    }

    /**
     * Liefert alle Paare sich überschneidender Zeiträume als Indexpaare.
     *
     * @param  array<string, DatePeriodRange>  $periods
     * @return list<array{0: string, 1: string, 2: DatePeriodRange}>
     */
    public static function overlappingPairs(array $periods): array
    {
        $keys = array_keys($periods);
        sort($keys);

        $overlaps = [];
        $count = count($keys);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $intersection = $periods[$keys[$i]]->intersect($periods[$keys[$j]]);

                if ($intersection instanceof DatePeriodRange) {
                    $overlaps[] = [$keys[$i], $keys[$j], $intersection];
                }
            }
        }

        return $overlaps;
    }

    /**
     * Summe der Tage aller Teilzeiträume, jeweils auf den Rahmen begrenzt.
     *
     * @param  list<DatePeriodRange>  $periods
     */
    public static function coveredDays(DatePeriodRange $frame, array $periods): int
    {
        $days = 0;

        foreach ($periods as $period) {
            $days += $frame->overlappingDays($period);
        }

        return $days;
    }

    /**
     * @param  list<DatePeriodRange>  $periods
     */
    public static function isFullyCovered(DatePeriodRange $frame, array $periods): bool
    {
        return self::gapsWithin($frame, $periods) === [];
    }
}
