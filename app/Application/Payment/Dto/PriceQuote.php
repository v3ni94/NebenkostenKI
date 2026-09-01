<?php

declare(strict_types=1);

namespace App\Application\Payment\Dto;

use App\Domain\Money\Money;

/**
 * Serverseitig berechneter Endpreis eines Abrechnungslaufs (Abschnitt 1.3,
 * ADR-010).
 *
 * VERBINDLICHE EIGENSCHAFTEN
 *
 *  1. Abrechnungseinheit ist eine ERZEUGTE Mieterabrechnung, nicht eine
 *     Wohnung. Bei einem Mieterwechsel entstehen je Einheit mehrere.
 *  2. Der Verbraucherpreis ist der Bruttopreis. Netto, Umsatzsteuer und Brutto
 *     werden getrennt ausgewiesen.
 *  3. Gerechnet wird aus dem Brutto zurueck. Netto plus Steuer ergibt daher
 *     immer exakt den Bruttobetrag; eine Rundungsdifferenz liegt in der Steuer.
 *  4. Die Werte stammen ausschliesslich aus der Datenbank und der
 *     Konfiguration, niemals aus einem Formular.
 */
final readonly class PriceQuote
{
    public function __construct(
        public int $statementCount,
        public int $unitGrossCent,
        public int $baseGrossCent,
        public int $grossCent,
        public int $netCent,
        public int $taxCent,
        public string $vatRatePercent,
        public string $currency,
    ) {}

    public function gross(): Money
    {
        return Money::fromCents($this->grossCent);
    }

    public function net(): Money
    {
        return Money::fromCents($this->netCent);
    }

    public function tax(): Money
    {
        return Money::fromCents($this->taxCent);
    }

    public function unitGross(): Money
    {
        return Money::fromCents($this->unitGrossCent);
    }

    public function base(): Money
    {
        return Money::fromCents($this->baseGrossCent);
    }

    /**
     * Nettoeinzelpreis je Abrechnung fuer die Rechnungsposition.
     *
     * Der Wert wird ausschliesslich fuer die Positionsdarstellung gebildet. Die
     * Summen der Rechnung stammen aus netCent, taxCent und grossCent, damit die
     * Rechnung in jedem Fall aufgeht.
     */
    public function unitNetCent(): int
    {
        if ($this->statementCount < 1) {
            return 0;
        }

        return intdiv($this->netCent - $this->baseNetCent(), $this->statementCount);
    }

    /**
     * Nettoanteil des Grundpreises. Ohne Grundpreis ist der Wert 0.
     */
    public function baseNetCent(): int
    {
        if ($this->baseGrossCent === 0) {
            return 0;
        }

        return VatDecomposition::netOf($this->baseGrossCent, $this->vatRatePercent);
    }

    public function hasBaseAmount(): bool
    {
        return $this->baseGrossCent > 0;
    }

    /**
     * Nachweis der verbindlichen Zerlegung.
     */
    public function isConsistent(): bool
    {
        return $this->netCent + $this->taxCent === $this->grossCent
            && $this->statementCount * $this->unitGrossCent + $this->baseGrossCent === $this->grossCent;
    }
}
