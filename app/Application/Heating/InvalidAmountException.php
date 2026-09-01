<?php

declare(strict_types=1);

namespace App\Application\Heating;

use RuntimeException;

/**
 * Ein erfasster Betrag ist nicht auswertbar.
 *
 * Es wird niemals ein Ersatzwert angenommen und niemals gerundet. Der Anwender
 * korrigiert die Eingabe.
 */
final class InvalidAmountException extends RuntimeException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Der Betrag "%s" ist nicht auswertbar. Bitte geben Sie den Betrag in der Form 1.234,56 an.',
            mb_substr($value, 0, 40)
        ));
    }
}
