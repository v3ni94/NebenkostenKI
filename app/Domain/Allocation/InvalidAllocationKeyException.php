<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Support\DomainException;
use InvalidArgumentException;

/**
 * Wird geworfen, wenn ein Verteilerschlüssel fachlich unbrauchbar ist.
 *
 * Ein Nenner von null oder ein negativer Wert läuft niemals still durch
 * (Pflichtenheft Abschnitt 12.5: "Nenner null oder negative Werte").
 */
final class InvalidAllocationKeyException extends InvalidArgumentException implements DomainException
{
    public static function zeroDenominator(AllocationKeyType $type): self
    {
        return new self(sprintf(
            'Der Verteilerschlüssel "%s" hat den Nenner null. Eine Verteilung ist damit nicht möglich.',
            $type->label()
        ));
    }

    public static function negativeDenominator(AllocationKeyType $type, string $denominator): self
    {
        return new self(sprintf(
            'Der Verteilerschlüssel "%s" hat einen negativen Nenner (%s).',
            $type->label(),
            $denominator
        ));
    }

    public static function negativeNumerator(AllocationKeyType $type, string $participantKey, string $numerator): self
    {
        return new self(sprintf(
            'Der Verteilerschlüssel "%s" hat für "%s" einen negativen Zähler (%s).',
            $type->label(),
            $participantKey,
            $numerator
        ));
    }

    public static function numeratorsExceedDenominator(AllocationKeyType $type, string $sum, string $denominator): self
    {
        return new self(sprintf(
            'Die Summe der Zähler (%s) des Verteilerschlüssels "%s" übersteigt den Nenner (%s).',
            $sum,
            $type->label(),
            $denominator
        ));
    }

    public static function emptyKey(AllocationKeyType $type): self
    {
        return new self(sprintf(
            'Der Verteilerschlüssel "%s" enthält keine Werte.',
            $type->label()
        ));
    }

    public static function missingUnit(AllocationKeyType $type, string $unitKey): self
    {
        return new self(sprintf(
            'Der Verteilerschlüssel "%s" enthält keinen Wert für die Einheit "%s".',
            $type->label(),
            $unitKey
        ));
    }
}
