<?php

declare(strict_types=1);

namespace App\Domain\Period;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Taggenauer Zeitraum mit INKLUSIVEM Start- und Endtag.
 *
 * Verbindliche Intervallsemantik der gesamten Engine (Abschnitt 11.2 des
 * Pflichtenhefts):
 *
 * - Start- und Endtag zählen beide mit.
 * - 01.01.2024 bis 31.12.2024 ergibt 366 Tage (Schaltjahr).
 * - 01.01.2025 bis 31.12.2025 ergibt 365 Tage.
 * - Ein Mieterwechsel mit Auszug am 30.06.2025 und Einzug am 01.07.2025 ist
 *   lückenlos und überschneidungsfrei: 181 Tage plus 184 Tage = 365 Tage.
 *
 * Uhrzeiten und Zeitzonen sind fachlich bedeutungslos; jedes Datum wird auf
 * den Kalendertag in UTC normalisiert, damit Tageszählungen unabhängig von
 * Sommerzeitwechseln exakt bleiben. Die Anzeige erfolgt in Europe/Berlin,
 * das ist Aufgabe der Presentation-Schicht.
 */
final readonly class DatePeriodRange
{
    private const string DATE_FORMAT = 'Y-m-d';

    public DateTimeImmutable $start;

    public DateTimeImmutable $end;

    public function __construct(DateTimeInterface $start, DateTimeInterface $end)
    {
        $normalizedStart = self::normalize($start);
        $normalizedEnd = self::normalize($end);

        if ($normalizedEnd < $normalizedStart) {
            throw InvalidPeriodException::endBeforeStart(
                $normalizedStart->format(self::DATE_FORMAT),
                $normalizedEnd->format(self::DATE_FORMAT)
            );
        }

        $this->start = $normalizedStart;
        $this->end = $normalizedEnd;
    }

    /**
     * Erzeugt einen Zeitraum aus ISO-Datumsangaben (JJJJ-MM-TT).
     */
    public static function fromIso(string $start, string $end): self
    {
        return new self(self::parse($start), self::parse($end));
    }

    /**
     * Erzeugt das vollständige Kalenderjahr.
     */
    public static function calendarYear(int $year): self
    {
        return self::fromIso(sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year));
    }

    /**
     * Anzahl der Kalendertage einschließlich Start- und Endtag.
     */
    public function days(): int
    {
        $diff = $this->start->diff($this->end);

        return (int) $diff->days + 1;
    }

    public function startIso(): string
    {
        return $this->start->format(self::DATE_FORMAT);
    }

    public function endIso(): string
    {
        return $this->end->format(self::DATE_FORMAT);
    }

    public function contains(DateTimeInterface $date): bool
    {
        $normalized = self::normalize($date);

        return $normalized >= $this->start && $normalized <= $this->end;
    }

    /**
     * Prüft, ob sich zwei Zeiträume um mindestens einen Kalendertag
     * überschneiden. Aneinander angrenzende Zeiträume (30.06. / 01.07.)
     * überschneiden sich nicht.
     */
    public function overlaps(self $other): bool
    {
        return $this->start <= $other->end && $other->start <= $this->end;
    }

    /**
     * Schnittmenge zweier Zeiträume oder null, wenn keine besteht.
     */
    public function intersect(self $other): ?self
    {
        if (! $this->overlaps($other)) {
            return null;
        }

        return new self(
            max($this->start, $other->start),
            min($this->end, $other->end)
        );
    }

    /**
     * Anzahl der Tage, die dieser Zeitraum mit einem anderen gemeinsam hat.
     */
    public function overlappingDays(self $other): int
    {
        return $this->intersect($other)?->days() ?? 0;
    }

    public function containsPeriod(self $other): bool
    {
        return $other->start >= $this->start && $other->end <= $this->end;
    }

    public function equals(self $other): bool
    {
        return $this->startIso() === $other->startIso() && $this->endIso() === $other->endIso();
    }

    /**
     * Grenzt unmittelbar an den Folgezeitraum an (Endtag + 1 Tag = Starttag).
     */
    public function isAdjacentTo(self $other): bool
    {
        return $this->end->modify('+1 day')->format(self::DATE_FORMAT) === $other->startIso()
            || $other->end->modify('+1 day')->format(self::DATE_FORMAT) === $this->startIso();
    }

    /**
     * Verschiebt die Grenzen tagesweise; wird für Lückenberechnung genutzt.
     */
    public function withStart(DateTimeInterface $start): self
    {
        return new self($start, $this->end);
    }

    public function withEnd(DateTimeInterface $end): self
    {
        return new self($this->start, $end);
    }

    /**
     * Deutsche Darstellung, z. B. "01.01.2025 bis 31.12.2025".
     */
    public function format(): string
    {
        return $this->start->format('d.m.Y').' bis '.$this->end->format('d.m.Y');
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            '!'.self::DATE_FORMAT,
            $value,
            new DateTimeZone('UTC')
        );

        if ($date === false) {
            throw InvalidPeriodException::unparsableDate($value);
        }

        if ($date->format(self::DATE_FORMAT) !== $value) {
            throw InvalidPeriodException::unparsableDate($value);
        }

        return $date;
    }

    private static function normalize(DateTimeInterface $date): DateTimeImmutable
    {
        return new DateTimeImmutable(
            $date->format(self::DATE_FORMAT).' 00:00:00',
            new DateTimeZone('UTC')
        );
    }
}
