<?php

declare(strict_types=1);

namespace App\Rules\Context;

use App\Domain\Money\Money;

/**
 * Stand der Finalisierung und der Zahlung eines Abrechnungslaufs.
 *
 * Eine bereits erfolgte Finalisierung darf nicht wiederholt werden. Eine
 * Korrektur erzeugt eine neue Version und ersetzt die alte nicht
 * (Pflichtenheft Abschnitt 11.5).
 */
final readonly class RuleFinalizationState
{
    public function __construct(
        public int $finalizedVersionCount = 0,
        public ?Money $expectedAmount = null,
        public ?Money $paidAmount = null,
        public bool $correctionApproved = false,
    ) {}
}
