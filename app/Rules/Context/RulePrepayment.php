<?php

declare(strict_types=1);

namespace App\Rules\Context;

use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * Eine Vorauszahlungszeile eines Mietverhaeltnisses.
 */
final readonly class RulePrepayment
{
    public function __construct(
        public string $key,
        public string $tenancyKey,
        public DatePeriodRange $period,
        public Money $target,
        public ?Money $actual = null,
        public bool $assumedEqualToTarget = false,
    ) {}
}
