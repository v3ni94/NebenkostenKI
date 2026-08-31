<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Support\GermanNumberFormatter;
use Brick\Math\BigDecimal;

/**
 * Verteilung nach Miteigentumsanteilen (MEA).
 *
 * Der Nenner ist regelmäßig der Gesamtnenner der Teilungserklärung
 * (z. B. 1.000,00) und nicht die Summe der Anteile des Objekts. Deckt das
 * Objekt nur einen Teil der WEG ab, verbleibt der Restanteil beim Eigentümer
 * beziehungsweise bei den übrigen Miteigentümern und wird von der Engine
 * getrennt ausgewiesen.
 *
 * Der WEG-Schlüssel wird nicht stillschweigend mit dem mietvertraglichen
 * Umlageschlüssel gleichgesetzt.
 */
final class CoOwnershipShareKey extends NumericAllocationKey
{
    public function type(): AllocationKeyType
    {
        return AllocationKeyType::CO_OWNERSHIP_SHARE;
    }

    public function explanationFor(string $participantKey): string
    {
        return sprintf(
            'Miteigentumsanteil %s von %s',
            GermanNumberFormatter::decimal($this->numeratorFor($participantKey), $this->displayScale()),
            GermanNumberFormatter::decimal($this->denominator(), $this->displayScale())
        );
    }

    /**
     * Erzeugt den Schlüssel mit ausdrücklich angegebenem Gesamtnenner.
     *
     * @param  array<string, BigDecimal|string|int>  $shares
     */
    public static function withTotalShares(array $shares, BigDecimal|string|int $totalShares): self
    {
        return new self($shares, $totalShares);
    }
}
