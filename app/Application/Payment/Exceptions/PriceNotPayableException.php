<?php

declare(strict_types=1);

namespace App\Application\Payment\Exceptions;

use App\Domain\Money\Money;
use RuntimeException;

/**
 * Der Endpreis kann nicht gebildet oder nicht abgerechnet werden.
 *
 * Die Meldungen sind deutsche Nutzertexte in Sie-Ansprache. Ein Betrag wird
 * immer im Format 1.234,56 EUR genannt.
 */
final class PriceNotPayableException extends RuntimeException
{
    public static function withoutStatements(): self
    {
        return new self(
            'Für diesen Abrechnungslauf liegt noch keine erzeugte Mieterabrechnung vor. '
            .'Bitte erstellen Sie zuerst die Vorschau.'
        );
    }

    public static function priceOutOfRange(int $unitGrossCent, int $minCent, int $maxCent): self
    {
        return new self(sprintf(
            'Der konfigurierte Preis je Mieterabrechnung von %s liegt außerhalb des freigegebenen Korridors '
            .'von %s bis %s. Bitte prüfen Sie die Preiskonfiguration, bevor eine Zahlung eingeleitet wird.',
            Money::fromCents($unitGrossCent)->format(),
            Money::fromCents($minCent)->format(),
            Money::fromCents($maxCent)->format(),
        ));
    }
}
