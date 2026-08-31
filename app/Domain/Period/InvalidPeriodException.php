<?php

declare(strict_types=1);

namespace App\Domain\Period;

use App\Domain\Support\DomainException;
use InvalidArgumentException;

/**
 * Wird geworfen, wenn ein Zeitraum fachlich unmöglich ist.
 */
final class InvalidPeriodException extends InvalidArgumentException implements DomainException
{
    public static function endBeforeStart(string $start, string $end): self
    {
        return new self(sprintf(
            'Das Ende eines Zeitraums darf nicht vor dem Beginn liegen: %s bis %s.',
            $start,
            $end
        ));
    }

    public static function unparsableDate(string $value): self
    {
        return new self(sprintf('Datum konnte nicht gelesen werden: "%s". Erwartet wird das Format JJJJ-MM-TT.', $value));
    }
}
