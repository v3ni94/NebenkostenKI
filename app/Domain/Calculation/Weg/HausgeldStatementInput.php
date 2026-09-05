<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Weg;

use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * WEG-Einzelabrechnung einer Eigentumseinheit (Pflichtenheft Abschnitt 7).
 *
 * Liegt nur der monatliche Hausgeldbetrag oder nur die Abrechnungsspitze ohne
 * Kostenaufschlüsselung vor, darf keine scheinbar vollständige Abrechnung
 * erzeugt werden (Abschnitt 7.5). Das prüft der HausgeldCostExtractor.
 */
final readonly class HausgeldStatementInput
{
    /**
     * @param  list<HausgeldPositionInput>  $positions
     */
    public function __construct(
        public string $unitKey,
        public DatePeriodRange $period,
        public array $positions,
        public ?Money $totalUnitShare = null,
        public ?Money $housemoneyPrepayments = null,
        public ?Money $settlementBalance = null,
        public string $wegLabel = '',
    ) {}

    /**
     * Enthält die Abrechnung eine Aufschlüsselung nach Kostenarten?
     */
    public function hasCostBreakdown(): bool
    {
        foreach ($this->positions as $position) {
            if (! $position->kind->isExcludedByRule()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<HausgeldPositionInput>
     */
    public function positionsOfKind(HausgeldPositionKind $kind): array
    {
        return array_values(array_filter(
            $this->positions,
            static fn (HausgeldPositionInput $position): bool => $position->kind === $kind
        ));
    }

    public function containsPropertyTax(): bool
    {
        return $this->positionsOfKind(HausgeldPositionKind::PROPERTY_TAX) !== [];
    }
}
