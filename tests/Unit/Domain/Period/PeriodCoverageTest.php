<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Period;

use App\Domain\Period\DatePeriodRange;
use App\Domain\Period\PeriodCoverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lücken- und Überschneidungsprüfung der Nutzungszeiträume einer Einheit.
 */
final class PeriodCoverageTest extends TestCase
{
    #[Test]
    public function lueckenloser_mieterwechsel_erzeugt_keine_luecke(): void
    {
        $gaps = PeriodCoverage::gapsWithin(DatePeriodRange::calendarYear(2025), [
            DatePeriodRange::fromIso('2025-01-01', '2025-06-30'),
            DatePeriodRange::fromIso('2025-07-01', '2025-12-31'),
        ]);

        $this->assertSame([], $gaps);
        $this->assertTrue(PeriodCoverage::isFullyCovered(DatePeriodRange::calendarYear(2025), [
            DatePeriodRange::fromIso('2025-01-01', '2025-06-30'),
            DatePeriodRange::fromIso('2025-07-01', '2025-12-31'),
        ]));
    }

    #[Test]
    public function leerstand_am_jahresanfang_wird_als_luecke_erkannt(): void
    {
        $gaps = PeriodCoverage::gapsWithin(DatePeriodRange::calendarYear(2025), [
            DatePeriodRange::fromIso('2025-04-01', '2025-12-31'),
        ]);

        $this->assertCount(1, $gaps);
        $this->assertSame('2025-01-01', $gaps[0]->startIso());
        $this->assertSame('2025-03-31', $gaps[0]->endIso());
        $this->assertSame(90, $gaps[0]->days());
    }

    #[Test]
    public function leerstand_in_der_jahresmitte_wird_als_luecke_erkannt(): void
    {
        $gaps = PeriodCoverage::gapsWithin(DatePeriodRange::calendarYear(2025), [
            DatePeriodRange::fromIso('2025-01-01', '2025-05-31'),
            DatePeriodRange::fromIso('2025-09-01', '2025-12-31'),
        ]);

        $this->assertCount(1, $gaps);
        $this->assertSame('2025-06-01', $gaps[0]->startIso());
        $this->assertSame('2025-08-31', $gaps[0]->endIso());
        $this->assertSame(92, $gaps[0]->days());
    }

    #[Test]
    public function leerstand_am_jahresende_wird_als_luecke_erkannt(): void
    {
        $gaps = PeriodCoverage::gapsWithin(DatePeriodRange::calendarYear(2025), [
            DatePeriodRange::fromIso('2025-01-01', '2025-11-30'),
        ]);

        $this->assertCount(1, $gaps);
        $this->assertSame('2025-12-01', $gaps[0]->startIso());
        $this->assertSame(31, $gaps[0]->days());
    }

    #[Test]
    public function ohne_nutzungszeitraum_ist_der_gesamte_zeitraum_luecke(): void
    {
        $gaps = PeriodCoverage::gapsWithin(DatePeriodRange::calendarYear(2025), []);

        $this->assertCount(1, $gaps);
        $this->assertSame(365, $gaps[0]->days());
    }

    #[Test]
    public function mehrere_luecken_werden_einzeln_ausgewiesen(): void
    {
        $gaps = PeriodCoverage::gapsWithin(DatePeriodRange::calendarYear(2025), [
            DatePeriodRange::fromIso('2025-02-01', '2025-03-31'),
            DatePeriodRange::fromIso('2025-06-01', '2025-10-31'),
        ]);

        $this->assertCount(3, $gaps);
        $this->assertSame('2025-01-01', $gaps[0]->startIso());
        $this->assertSame('2025-04-01', $gaps[1]->startIso());
        $this->assertSame('2025-11-01', $gaps[2]->startIso());
        $this->assertSame(31 + 61 + 61, $gaps[0]->days() + $gaps[1]->days() + $gaps[2]->days());
    }

    #[Test]
    public function ueberschneidende_zeitraeume_werden_paarweise_gemeldet(): void
    {
        $overlaps = PeriodCoverage::overlappingPairs([
            'mv-2' => DatePeriodRange::fromIso('2025-01-01', '2025-07-15'),
            'mv-3' => DatePeriodRange::fromIso('2025-07-01', '2025-12-31'),
            'mv-1' => DatePeriodRange::fromIso('2025-01-01', '2025-06-30'),
        ]);

        $this->assertCount(2, $overlaps);
        $this->assertSame('mv-1', $overlaps[0][0]);
        $this->assertSame('mv-2', $overlaps[0][1]);
        $this->assertSame('2025-01-01', $overlaps[0][2]->startIso());
        $this->assertSame('mv-2', $overlaps[1][0]);
        $this->assertSame('mv-3', $overlaps[1][1]);
        $this->assertSame(15, $overlaps[1][2]->days());
    }

    #[Test]
    public function abgedeckte_tage_werden_auf_den_rahmen_begrenzt(): void
    {
        $covered = PeriodCoverage::coveredDays(DatePeriodRange::calendarYear(2025), [
            DatePeriodRange::fromIso('2024-11-01', '2025-01-31'),
            DatePeriodRange::fromIso('2025-02-01', '2026-03-31'),
        ]);

        $this->assertSame(365, $covered);
    }
}
