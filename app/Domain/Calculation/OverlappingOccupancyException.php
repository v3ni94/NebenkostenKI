<?php

declare(strict_types=1);

namespace App\Domain\Calculation;

use App\Domain\Period\DatePeriodRange;
use App\Domain\Support\DomainException;
use RuntimeException;

/**
 * Wird geworfen, wenn sich zwei Nutzungszeiträume derselben Einheit
 * überschneiden.
 *
 * Ein Mieterwechsel ist überschneidungsfrei abzubilden (Pflichtenheft
 * Abschnitt 11.2). Beispiel: Auszug 30.06., Einzug 01.07. Eine
 * Überschneidung würde Kosten doppelt verteilen und ist deshalb ein harter
 * Eingabefehler.
 */
final class OverlappingOccupancyException extends RuntimeException implements DomainException
{
    public static function between(string $unitKey, string $firstKey, string $secondKey, DatePeriodRange $overlap): self
    {
        return new self(sprintf(
            'Die Nutzungszeiträume "%s" und "%s" der Einheit "%s" überschneiden sich im Zeitraum %s.',
            $firstKey,
            $secondKey,
            $unitKey,
            $overlap->format()
        ));
    }
}
