<?php

declare(strict_types=1);

namespace App\Application\Payment\Exceptions;

use RuntimeException;

/**
 * Die produktive Rechnungserzeugung ist blockiert (Abschnitt 2.1, 15.2).
 *
 * Fehlende Steuer- und Bankdaten werden niemals erfunden. Solange sie fehlen
 * oder die Stammdaten nicht ausdruecklich bestaetigt sind, wird keine Rechnung
 * festgeschrieben und keine Rechnungsnummer verbraucht.
 */
final class OperatorMasterdataMissingException extends RuntimeException
{
    /**
     * @param  list<string>  $missingFields
     */
    public function __construct(
        string $message,
        public readonly array $missingFields,
        public readonly bool $masterdataConfirmed,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $missingFields
     */
    public static function forFields(array $missingFields, bool $masterdataConfirmed): self
    {
        $message = $missingFields === []
            ? 'Die Stammdaten des Betreibers sind noch nicht ausdrücklich bestätigt. '
                .'Bis zur Bestätigung wird keine Rechnung erzeugt.'
            : sprintf(
                'Die Rechnungserzeugung ist blockiert. Es fehlen folgende Pflichtangaben des Betreibers: %s. '
                .'Diese Angaben werden nicht ergänzt oder geschätzt.',
                implode(', ', $missingFields),
            );

        return new self($message, $missingFields, $masterdataConfirmed);
    }
}
