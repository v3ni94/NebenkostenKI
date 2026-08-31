<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Domain\Support\DomainException;
use RuntimeException;

/**
 * Wird geworfen, wenn Beträge unterschiedlicher Währungen verrechnet werden.
 */
final class CurrencyMismatchException extends RuntimeException implements DomainException
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self(sprintf(
            'Beträge unterschiedlicher Währungen können nicht verrechnet werden: %s und %s.',
            $left->value,
            $right->value
        ));
    }
}
