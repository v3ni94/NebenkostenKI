<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Period;

use App\Domain\Period\DatePeriodRange;
use App\Domain\Period\InvalidPeriodException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verbindliche Intervallsemantik: Start- und Endtag zählen beide mit
 * (Pflichtenheft Abschnitt 11.2).
 */
final class DatePeriodRangeTest extends TestCase
{
    #[Test]
    public function kalenderjahr_2024_hat_366_tage_schaltjahr(): void
    {
        $this->assertSame(366, DatePeriodRange::fromIso('2024-01-01', '2024-12-31')->days());
        $this->assertSame(366, DatePeriodRange::calendarYear(2024)->days());
    }

    #[Test]
    public function kalenderjahr_2025_hat_365_tage(): void
    {
        $this->assertSame(365, DatePeriodRange::calendarYear(2025)->days());
    }

    #[Test]
    public function februar_im_schaltjahr_hat_29_tage(): void
    {
        $this->assertSame(29, DatePeriodRange::fromIso('2024-02-01', '2024-02-29')->days());
        $this->assertSame(28, DatePeriodRange::fromIso('2025-02-01', '2025-02-28')->days());
    }

    #[Test]
    public function ein_einzelner_tag_hat_einen_tag(): void
    {
        $this->assertSame(1, DatePeriodRange::fromIso('2025-06-30', '2025-06-30')->days());
    }

    #[Test]
    public function mieterwechsel_zum_30_juni_ist_lueckenlos_und_ergibt_365_tage(): void
    {
        $auszug = DatePeriodRange::fromIso('2025-01-01', '2025-06-30');
        $einzug = DatePeriodRange::fromIso('2025-07-01', '2025-12-31');

        $this->assertSame(181, $auszug->days());
        $this->assertSame(184, $einzug->days());
        $this->assertSame(365, $auszug->days() + $einzug->days());
        $this->assertFalse($auszug->overlaps($einzug));
        $this->assertTrue($auszug->isAdjacentTo($einzug));
    }

    #[Test]
    public function mieterwechsel_im_schaltjahr_ergibt_182_plus_184_tage(): void
    {
        $auszug = DatePeriodRange::fromIso('2024-01-01', '2024-06-30');
        $einzug = DatePeriodRange::fromIso('2024-07-01', '2024-12-31');

        $this->assertSame(182, $auszug->days());
        $this->assertSame(184, $einzug->days());
        $this->assertSame(366, $auszug->days() + $einzug->days());
    }

    #[Test]
    public function endtag_vor_starttag_ist_unzulaessig(): void
    {
        $this->expectException(InvalidPeriodException::class);

        DatePeriodRange::fromIso('2025-12-31', '2025-01-01');
    }

    #[Test]
    public function unlesbares_datum_wird_abgewiesen(): void
    {
        $this->expectException(InvalidPeriodException::class);

        DatePeriodRange::fromIso('31.12.2025', '2025-12-31');
    }

    #[Test]
    public function kein_datum_ausserhalb_des_kalenders(): void
    {
        $this->expectException(InvalidPeriodException::class);

        DatePeriodRange::fromIso('2025-02-30', '2025-12-31');
    }

    #[Test]
    public function schnittmenge_wird_taggenau_gebildet(): void
    {
        $jahr = DatePeriodRange::calendarYear(2025);
        $miete = DatePeriodRange::fromIso('2024-09-15', '2025-03-31');

        $schnitt = $jahr->intersect($miete);

        $this->assertInstanceOf(DatePeriodRange::class, $schnitt);
        $this->assertSame('2025-01-01', $schnitt->startIso());
        $this->assertSame('2025-03-31', $schnitt->endIso());
        $this->assertSame(90, $schnitt->days());
        $this->assertSame(90, $jahr->overlappingDays($miete));
    }

    #[Test]
    public function ohne_schnittmenge_wird_null_geliefert(): void
    {
        $jahr = DatePeriodRange::calendarYear(2025);

        $this->assertNull($jahr->intersect(DatePeriodRange::fromIso('2024-01-01', '2024-12-31')));
        $this->assertSame(0, $jahr->overlappingDays(DatePeriodRange::fromIso('2026-01-01', '2026-01-31')));
    }

    #[Test]
    public function ueberschneidung_wird_erkannt(): void
    {
        $erste = DatePeriodRange::fromIso('2025-01-01', '2025-07-01');
        $zweite = DatePeriodRange::fromIso('2025-07-01', '2025-12-31');

        $this->assertTrue($erste->overlaps($zweite));
        $this->assertSame(1, $erste->overlappingDays($zweite));
    }

    #[Test]
    public function enthaltensein_von_datum_und_zeitraum(): void
    {
        $jahr = DatePeriodRange::calendarYear(2025);

        $this->assertTrue($jahr->contains(new \DateTimeImmutable('2025-01-01')));
        $this->assertTrue($jahr->contains(new \DateTimeImmutable('2025-12-31')));
        $this->assertFalse($jahr->contains(new \DateTimeImmutable('2024-12-31')));
        $this->assertTrue($jahr->containsPeriod(DatePeriodRange::fromIso('2025-03-01', '2025-03-31')));
        $this->assertFalse($jahr->containsPeriod(DatePeriodRange::fromIso('2024-12-01', '2025-01-31')));
    }

    #[Test]
    public function deutsche_darstellung_des_zeitraums(): void
    {
        $this->assertSame(
            '01.01.2025 bis 31.12.2025',
            DatePeriodRange::calendarYear(2025)->format()
        );
        $this->assertSame(
            '01.07.2025 bis 31.12.2025',
            (string) DatePeriodRange::fromIso('2025-07-01', '2025-12-31')
        );
    }

    #[Test]
    public function gleichheit_beruht_auf_kalendertagen(): void
    {
        $this->assertTrue(
            DatePeriodRange::calendarYear(2025)->equals(DatePeriodRange::fromIso('2025-01-01', '2025-12-31'))
        );
        $this->assertFalse(
            DatePeriodRange::calendarYear(2025)->equals(DatePeriodRange::fromIso('2025-01-01', '2025-12-30'))
        );
    }

    /**
     * Monats- und Jahresgrenzen: Einzug und Auszug an typischen Stichtagen.
     *
     * @param  int  $expectedDays  handgeprüfte Tageszahl
     */
    #[Test]
    #[DataProvider('monatsgrenzen')]
    public function einzug_und_auszug_an_monatsgrenzen(string $start, string $end, int $expectedDays): void
    {
        $this->assertSame($expectedDays, DatePeriodRange::fromIso($start, $end)->days());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function monatsgrenzen(): array
    {
        return [
            'Einzug 01.01., Auszug 31.01.' => ['2025-01-01', '2025-01-31', 31],
            'Einzug 01.02., Auszug 28.02.' => ['2025-02-01', '2025-02-28', 28],
            'Einzug 01.03., Auszug 31.12.' => ['2025-03-01', '2025-12-31', 306],
            'Einzug 15.04., Auszug 14.05.' => ['2025-04-15', '2025-05-14', 30],
            'Auszug 30.11., Jahresende' => ['2025-01-01', '2025-11-30', 334],
            'Leerstand Dezember' => ['2025-12-01', '2025-12-31', 31],
            'Leerstand Januar' => ['2025-01-01', '2025-01-31', 31],
            'Jahreswechsel im Schaltjahr' => ['2024-02-01', '2024-03-01', 30],
        ];
    }
}
