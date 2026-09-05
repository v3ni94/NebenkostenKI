<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Ein Regelstand mit Gueltigkeitsbeginn.
 *
 * Die Zusammensetzung eines Regelstands ergibt sich aus den Regeln, die an
 * seinem Stichtag gueltig sind. Der Stichtag ist damit die reproduzierbare
 * Grundlage eines bezahlten Berechnungsstands.
 */
final readonly class RulesetGeneration
{
    public DateTimeImmutable $validFrom;

    public function __construct(
        public string $version,
        string $validFrom,
        public string $note,
    ) {
        $this->validFrom = new DateTimeImmutable($validFrom.' 00:00:00', new DateTimeZone('UTC'));
    }

    /**
     * Stichtag, mit dem die Zusammensetzung dieses Regelstands bestimmt wird.
     */
    public function referenceDate(): DateTimeImmutable
    {
        return $this->validFrom;
    }
}
