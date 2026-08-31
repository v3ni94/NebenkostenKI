<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

use App\Domain\Support\DomainException;
use RuntimeException;

/**
 * Wird geworfen, wenn eine Eigenberechnung der Heizkosten nach HeizkostenV
 * mit unvollständigen Daten angefordert wird (Fall B).
 *
 * Pflichtenheft Abschnitt 12.3, Fall B: Eine vollständige Eigenberechnung
 * wird nur freigeschaltet, wenn Grundkosten, Verbrauchskosten,
 * Verbrauchswerte, Brennstoffbestand, Betriebsstrom, Warmwasseranteil und
 * CO2-Angaben vollständig vorliegen. Bei unvollständigen Daten darf es keine
 * scheinbar korrekte Automatik geben.
 */
final class IncompleteHeatingDataException extends RuntimeException implements DomainException
{
    /** @var list<string> */
    public readonly array $missingFields;

    /**
     * @param  list<string>  $missingFields
     */
    public function __construct(string $message, array $missingFields = [])
    {
        parent::__construct($message);

        $this->missingFields = $missingFields;
    }

    /**
     * @param  list<string>  $missingFields
     */
    public static function missingFields(array $missingFields): self
    {
        return new self(
            sprintf(
                'Die Heizkostenberechnung nach HeizkostenV ist mit den vorliegenden Daten nicht möglich. '
                .'Fehlende Angaben: %s. Es wird keine Schätzung vorgenommen.',
                implode(', ', $missingFields)
            ),
            $missingFields
        );
    }
}
