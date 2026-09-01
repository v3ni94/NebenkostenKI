<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Dto;

/**
 * Ergebnis der Grundsteuerpruefung (Abschnitt 7.3).
 *
 * added = false bedeutet: die Grundsteuer wurde NICHT addiert. Bei einer
 * moeglichen Dublette entsteht stattdessen eine Pruefaufgabe.
 */
final readonly class PropertyTaxOutcome
{
    public function __construct(
        public MappingOutcome $mapping,
        public bool $added,
        public bool $possibleDuplicate,
        public string $explanation,
        public ?int $annualAmountCent = null,
        public ?string $fileReference = null,
        public ?string $periodLabel = null,
        public bool $needsPeriodConfirmation = false,
    ) {}
}
