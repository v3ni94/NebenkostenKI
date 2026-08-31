<?php

declare(strict_types=1);

namespace App\Domain\Calculation;

/**
 * Art eines Nutzungszeitraums einer Einheit.
 *
 * TENANCY         Mietverhältnis; die Anteile werden dem Mieter umgelegt.
 * VACANCY         Erfasster Leerstand; die Anteile trägt der Eigentümer.
 * OWNER_RESIDUAL  Von der Engine ergänzter, nicht belegter Zeitraum einer
 *                 Einheit. Er wird wie Leerstand behandelt, damit niemals
 *                 stillschweigend Kosten auf die übrigen Mieter verschoben
 *                 werden.
 */
enum OccupancyKind: string
{
    case TENANCY = 'TENANCY';
    case VACANCY = 'VACANCY';
    case OWNER_RESIDUAL = 'OWNER_RESIDUAL';

    public function isChargedToOwner(): bool
    {
        return $this !== self::TENANCY;
    }

    public function label(): string
    {
        return match ($this) {
            self::TENANCY => 'Mietverhältnis',
            self::VACANCY => 'Leerstand',
            self::OWNER_RESIDUAL => 'nicht belegter Zeitraum',
        };
    }
}
