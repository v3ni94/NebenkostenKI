<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * Verteilerschlüssel: liefert je Beteiligtem einen Zähler, den Gesamtnenner
 * und einen menschenlesbaren Erklärungstext für das PDF.
 *
 * "Beteiligter" ist je nach Bezugsebene (scope()) entweder eine Einheit oder
 * ein Nutzungszeitraum (Mietverhältnis bzw. Leerstand).
 *
 * Implementierungen sind unveränderlich und rechnen ausschließlich mit
 * BigDecimal/BigRational, damit keine binären Floats entstehen.
 */
interface AllocationKey
{
    public function type(): AllocationKeyType;

    public function scope(): AllocationKeyScope;

    /**
     * Bezeichnung des Schlüssels für Tabellenspalten, z. B. "Wohnfläche".
     */
    public function label(): string;

    /**
     * Schlüssel aller Beteiligten, aufsteigend sortiert.
     *
     * @return list<string>
     */
    public function participantKeys(): array;

    public function hasParticipant(string $participantKey): bool;

    /**
     * Zähler des Beteiligten. Unbekannte Beteiligte liefern 0.
     */
    public function numeratorFor(string $participantKey): BigDecimal;

    /**
     * Gesamtnenner des Schlüssels. Niemals 0 oder negativ.
     */
    public function denominator(): BigDecimal;

    /**
     * Exakter Anteil des Beteiligten als Bruch, ohne Zwischenrundung.
     */
    public function shareFor(string $participantKey): BigRational;

    /**
     * Erklärungstext für das PDF, z. B. "Wohnfläche 72,50 m² von 310,00 m²".
     */
    public function explanationFor(string $participantKey): string;

    /**
     * Formatierter Zähler für die Ergebniszeile, z. B. "72,50".
     */
    public function formattedNumeratorFor(string $participantKey): string;

    /**
     * Formatierter Nenner für die Ergebniszeile, z. B. "310,00".
     */
    public function formattedDenominator(): string;
}
