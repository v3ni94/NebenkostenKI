<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Fachliche Zeitzone der Anwendung (ARCHITECTURE.md ADR-018).
 *
 * Rechnungsdatum, Nummernkreisjahr und Tagesgrenzen werden immer in
 * Europe/Berlin gebildet, unabhaengig davon, welche Zeitzone in app.timezone
 * oder auf dem Server eingestellt ist. Eine Zahlung am 01.01. um 00:30 Uhr
 * deutscher Zeit gehoert zum neuen Jahr, auch wenn die Serveruhr in UTC noch
 * den 31.12. zeigt. Die Klasse ist die eine Stelle, an der diese Zeitzone
 * benannt wird; Aufrufer verlassen sich nicht auf die Konfiguration.
 */
final class BusinessTimezone
{
    public const string NAME = 'Europe/Berlin';

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::NAME);
    }

    /**
     * Aktueller Zeitpunkt in der fachlichen Zeitzone. Beruecksichtigt eine im
     * Test gesetzte Zeit (Carbon::setTestNow).
     */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone());
    }

    /**
     * Heutiger Kalendertag in der fachlichen Zeitzone, ohne Uhrzeit.
     */
    public static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::now()->format('Y-m-d'), self::zone());
    }

    /**
     * Laufendes Kalenderjahr in der fachlichen Zeitzone.
     */
    public static function currentYear(): int
    {
        return (int) self::now()->format('Y');
    }
}
