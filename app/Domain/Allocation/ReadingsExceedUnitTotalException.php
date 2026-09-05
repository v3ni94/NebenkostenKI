<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Support\DomainException;
use RuntimeException;

/**
 * Wird geworfen, wenn die Zwischenablesungen einer Einheit zusammen mehr
 * ergeben als der erfasste Jahresverbrauch der Einheit, obwohl noch
 * Nutzungszeiträume ohne eigene Ablesung zu versorgen sind.
 *
 * Grundsatz 5 des Pflichtenhefts: Widersprüchliche Werte werden nicht still
 * bereinigt. Ein negativer Rest darf nicht auf null gesetzt werden, weil damit
 * die übrigen Nutzer unbemerkt den Verbrauch null erhielten und der Widerspruch
 * in den Ablesewerten verborgen bliebe (Befund R1).
 */
final class ReadingsExceedUnitTotalException extends RuntimeException implements DomainException
{
    private function __construct(
        string $message,
        public readonly string $unitKey,
        public readonly string $readingsSum,
        public readonly string $unitTotal,
    ) {
        parent::__construct($message);
    }

    public static function forUnit(string $unitKey, string $readingsSum, string $unitTotal): self
    {
        return new self(
            sprintf(
                'Die Zwischenablesungen der Einheit "%s" ergeben zusammen %s, der erfasste Jahresverbrauch der '
                .'Einheit beträgt jedoch nur %s. Der Widerspruch ist zu klären; es wird kein Rest angenommen.',
                $unitKey,
                $readingsSum,
                $unitTotal
            ),
            $unitKey,
            $readingsSum,
            $unitTotal,
        );
    }
}
