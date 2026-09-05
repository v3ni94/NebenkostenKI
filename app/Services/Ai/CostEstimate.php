<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Geschaetzte Kosten eines einzelnen KI-Aufrufs.
 *
 * DOKUMENTIERTE ANNAHME: Grundlage ist
 * ai.cost_basis_us_cent_per_million_tokens. Die Werte sind eine Annahme zum
 * Projektstand und vor Livegang sowie regelmaessig gegen die offizielle
 * Preisliste des Providers zu pruefen. Sie dienen ausschliesslich der
 * internen Kostenkontrolle und den Tageslimits, nicht der Abrechnung
 * gegenueber dem Nutzer.
 *
 * WEITERE ANNAHME: Die Kalkulationsbasis ist in US-Cent angegeben. Es wird
 * bewusst KEINE Wechselkursumrechnung vorgenommen, weil ein geschaetzter
 * Kurs eine weitere unbelegte Annahme waere. Das Tagesbudget aus
 * ai.max_daily_cost_cent_per_user wird in derselben Einheit verglichen. Diese
 * Festlegung ist vor Livegang zu bestaetigen.
 *
 * milliCent ist Tausendstel-Cent. Die feinere Einheit ist notwendig, weil ein
 * einzelner Klassifikationsaufruf deutlich unter einem Cent kostet und eine
 * Aufrundung je Aufruf das Tagesbudget stark verzerren wuerde.
 */
final class CostEstimate
{
    public function __construct(
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $costMilliCent,
        public readonly bool $basisAvailable,
    ) {}

    public static function withoutBasis(string $model, int $inputTokens, int $outputTokens): self
    {
        return new self($model, $inputTokens, $outputTokens, 0, false);
    }

    /**
     * Kosten in ganzen Cent, kaufmaennisch aufgerundet.
     *
     * Aufrundung, weil eine Unterschaetzung das Tagesbudget aushebeln wuerde.
     * Liegt fuer das Modell keine Kalkulationsbasis vor, ist das Ergebnis
     * null und nicht 0, damit ein fehlender Preis nicht als Nulltarif
     * missverstanden wird.
     */
    public function costCent(): ?int
    {
        if (! $this->basisAvailable) {
            return null;
        }

        return (int) ceil($this->costMilliCent / 1000);
    }

    public function costMilliCentOrNull(): ?int
    {
        return $this->basisAvailable ? $this->costMilliCent : null;
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
