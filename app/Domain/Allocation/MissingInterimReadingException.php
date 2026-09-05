<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Support\DomainException;
use RuntimeException;

/**
 * Wird geworfen, wenn ein Verbrauch bei Nutzerwechsel ohne Zwischenablesung
 * geteilt werden müsste.
 *
 * Grundsatz 5 des Pflichtenhefts: Fehlende Werte werden niemals geschätzt.
 * Eine Aufteilung ist nur mit Zwischenablesung zulässig oder über eine
 * ausdrücklich bestätigte Ersatzverteilung, die im Ergebnis gekennzeichnet
 * wird.
 */
final class MissingInterimReadingException extends RuntimeException implements DomainException
{
    public static function forUnit(string $unitKey, int $occupancyCount): self
    {
        return new self(sprintf(
            'Für die Einheit "%s" liegen %d Nutzungszeiträume, aber keine Zwischenablesung vor. '
            .'Eine Verbrauchsaufteilung ist ohne Zwischenablesung nur mit ausdrücklich bestätigter '
            .'Ersatzverteilung zulässig.',
            $unitKey,
            $occupancyCount
        ));
    }

    public static function readingMismatch(string $unitKey, string $expected, string $actual): self
    {
        return new self(sprintf(
            'Die Zwischenablesungen der Einheit "%s" ergeben %s, der Gesamtverbrauch beträgt jedoch %s. '
            .'Die Abweichung muss geklärt werden.',
            $unitKey,
            $actual,
            $expected
        ));
    }
}
