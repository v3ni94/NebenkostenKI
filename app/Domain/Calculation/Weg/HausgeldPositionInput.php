<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Weg;

use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;

/**
 * Eine Position der WEG-Einzelabrechnung.
 *
 * unitShare ist der auf die konkrete Eigentumseinheit entfallende Anteil.
 * totalAmount ist die Gesamtsumme der WEG für diese Kostenart und dient nur
 * der Plausibilisierung, nicht der Umlage.
 *
 * declaredAllocable spiegelt die Kennzeichnung des Verwalters. Sie ist ein
 * Vorschlag und KEINE automatische Rechtsfreigabe; maßgeblich ist die Art der
 * Position (HausgeldPositionKind) und der Mietvertrag.
 */
final readonly class HausgeldPositionInput
{
    public function __construct(
        public string $positionKey,
        public string $label,
        public string $categoryKey,
        public Money $unitShare,
        public HausgeldPositionKind $kind = HausgeldPositionKind::OPERATING_COST,
        public ?Money $totalAmount = null,
        public ?bool $declaredAllocable = null,
        public TaxBenefitCategory $taxBenefitCategory = TaxBenefitCategory::NONE,
        public ?Money $laborShare = null,
        public bool $laborShareDisclosed = true,
    ) {}
}
