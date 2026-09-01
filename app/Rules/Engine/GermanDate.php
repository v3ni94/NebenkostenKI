<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use App\Domain\Period\DatePeriodRange;
use DateTimeInterface;

/**
 * Datumsdarstellung der Regeltexte im Format TT.MM.JJJJ.
 */
final class GermanDate
{
    public static function day(DateTimeInterface $date): string
    {
        return $date->format('d.m.Y');
    }

    public static function period(DatePeriodRange $period): string
    {
        return self::day($period->start).' bis '.self::day($period->end);
    }
}
