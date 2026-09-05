<?php

declare(strict_types=1);

namespace App\Application\Payment\Exceptions;

use RuntimeException;

/**
 * Die Rechnungsanschrift des Kunden fehlt oder ist unvollstaendig.
 *
 * Der Checkout verlangt die Anschrift; der Kunde kann sie danach im Konto
 * leeren. Eine Rechnung ohne Anschrift wird nicht festgeschrieben und
 * verbraucht keine Nummer. Die bezahlte Leistung wird trotzdem bereitgestellt,
 * der Fall erscheint im Zahlungsnachlauf und wird nach Ergaenzung der
 * Anschrift nachgeholt.
 */
final class CustomerAddressMissingException extends RuntimeException
{
    public const string BLOCKER = 'Rechnungsanschrift des Kunden';

    public static function forBillingRun(): self
    {
        return new self(
            'Die Rechnungsanschrift des Kunden (Straße, Postleitzahl, Ort) fehlt. Es wird keine Rechnung '
            .'erzeugt, bis die Anschrift im Kundenkonto ergänzt ist.'
        );
    }
}
