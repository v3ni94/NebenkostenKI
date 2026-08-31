<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

use App\Domain\Support\DomainException;
use RuntimeException;

/**
 * Wird geworfen, wenn die Eigenberechnung nach HeizkostenV (Fall B) trotz
 * vollständiger Daten angefordert wird, solange das Modul fachlich nicht
 * freigeschaltet ist.
 *
 * Damit ist ausgeschlossen, dass eine unfertige Automatik ein scheinbar
 * korrektes Ergebnis liefert. Die Freischaltung erfolgt erst nach
 * vollständiger Umsetzung und Prüfung des HeizkostenV-Moduls.
 */
final class HeatingCalculationNotReleasedException extends RuntimeException implements DomainException
{
    public static function create(): self
    {
        return new self(
            'Die Eigenberechnung der Heizkosten nach HeizkostenV ist noch nicht freigeschaltet. '
            .'Bitte eine externe Heizkostenabrechnung verwenden (Fall A) oder die Freischaltung des Moduls abwarten.'
        );
    }
}
