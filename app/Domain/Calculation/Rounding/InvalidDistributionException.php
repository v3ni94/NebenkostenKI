<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Rounding;

use App\Domain\Support\DomainException;
use InvalidArgumentException;

/**
 * Wird geworfen, wenn eine Verteilung mathematisch unzulässig ist.
 */
final class InvalidDistributionException extends InvalidArgumentException implements DomainException
{
    public static function emptyWeights(): self
    {
        return new self('Eine Verteilung ohne Gewichte ist nicht möglich.');
    }

    public static function negativeWeight(string $participantKey, string $weight): self
    {
        return new self(sprintf(
            'Das Gewicht des Beteiligten "%s" ist negativ (%s).',
            $participantKey,
            $weight
        ));
    }

    public static function weightsNotNormalized(string $sum): self
    {
        return new self(sprintf(
            'Die Summe der Gewichte muss exakt 1 betragen, tatsächlich %s. '
            .'Ein nicht verteilter Restanteil ist als eigener Beteiligter zu übergeben.',
            $sum
        ));
    }
}
