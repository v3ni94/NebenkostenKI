<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use RuntimeException;

/**
 * Ein gespeicherter Regelstand ist nicht mehr im Katalog vorhanden.
 *
 * Regelstaende werden niemals entfernt, damit alte Abrechnungen
 * reproduzierbar bleiben. Diese Ausnahme weist auf einen Fehler im Katalog
 * oder auf einen fremden Datenbestand hin.
 */
final class UnknownRulesetVersionException extends RuntimeException
{
    public static function forVersion(string $version): self
    {
        return new self(sprintf(
            'Der Regelstand "%s" ist im Katalog nicht vorhanden. Ein bezahlter Berechnungsstand kann '
            .'ohne seinen Regelstand nicht reproduziert werden.',
            $version
        ));
    }
}
