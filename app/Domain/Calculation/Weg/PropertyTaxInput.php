<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Weg;

use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * Grundsteuerbescheid einer Einheit (Pflichtenheft Abschnitt 7.3).
 *
 * directlyAssignedToUnit ist true, wenn die Grundsteuer eindeutig separat und
 * der Einheit direkt zugeordnet ist. periodConfirmed ist true, wenn ein
 * Teilzeitraum oder ein Eigentumswechsel vom Nutzer ausdrücklich bestätigt
 * wurde; ohne Bestätigung wird nicht geraten.
 */
final readonly class PropertyTaxInput
{
    public function __construct(
        public string $unitKey,
        public Money $annualAmount,
        public DatePeriodRange $period,
        public bool $directlyAssignedToUnit = true,
        public bool $periodConfirmed = false,
        public ?string $fileReference = null,
    ) {}
}
