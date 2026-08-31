<?php

declare(strict_types=1);

namespace App\Domain\Money;

/**
 * Währungen der Domain-Schicht.
 *
 * Domain-eigenes Enum: die Persistenzschicht bildet ihre eigenen Enums bzw.
 * Spaltenwerte auf dieses Enum ab. Für den MVP ist ausschließlich EUR
 * fachlich freigegeben.
 */
enum Currency: string
{
    case EUR = 'EUR';

    public function symbol(): string
    {
        return match ($this) {
            self::EUR => 'EUR',
        };
    }
}
