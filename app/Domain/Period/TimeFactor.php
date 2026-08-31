<?php

declare(strict_types=1);

namespace App\Domain\Period;

use App\Domain\Support\GermanNumberFormatter;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Zeitanteil einer Kostenzeile: Nutzungstage im Verhältnis zu den Tagen des
 * Abrechnungszeitraums.
 *
 * Zwei Ausprägungen:
 * - angewandt: der Anteil der Einheit wird zusätzlich mit
 *   Nutzungstage / Tage des Abrechnungszeitraums gewichtet.
 * - im Schlüssel enthalten: bei Personentagen, Verbrauch und
 *   Direktzuordnung ist die Zeitgewichtung bereits Teil des Zählers. Ein
 *   zusätzlicher Faktor würde die Zeit doppelt berücksichtigen, deshalb ist
 *   der Faktor dann exakt 1.
 */
final readonly class TimeFactor
{
    private function __construct(
        public int $daysUsed,
        public int $daysInPeriod,
        public bool $includedInAllocationKey,
    ) {}

    public static function applied(int $daysUsed, int $daysInPeriod): self
    {
        return new self($daysUsed, $daysInPeriod, false);
    }

    /**
     * Zeitgewichtung ist bereits im Verteilerschlüssel enthalten.
     */
    public static function includedInKey(int $daysUsed, int $daysInPeriod): self
    {
        return new self($daysUsed, $daysInPeriod, true);
    }

    /**
     * Exakter Faktor als Bruch, ohne jede Zwischenrundung.
     */
    public function factor(): BigRational
    {
        if ($this->includedInAllocationKey) {
            return BigRational::one();
        }

        if ($this->daysInPeriod === 0) {
            return BigRational::zero();
        }

        return BigRational::nd($this->daysUsed, $this->daysInPeriod);
    }

    /**
     * Menschenlesbarer Text für das PDF, z. B. "184 von 365 Tagen".
     */
    public function explanation(): string
    {
        $text = sprintf('%d von %d Tagen', $this->daysUsed, $this->daysInPeriod);

        if ($this->includedInAllocationKey) {
            return $text.' (Zeitanteil im Verteilerschlüssel enthalten)';
        }

        return $text;
    }

    /**
     * Faktor als gerundete Dezimaldarstellung, nur für die Anzeige.
     */
    public function formattedFactor(int $scale = 6): string
    {
        return GermanNumberFormatter::decimal($this->factor()->toScale($scale, RoundingMode::HALF_UP), $scale);
    }
}
