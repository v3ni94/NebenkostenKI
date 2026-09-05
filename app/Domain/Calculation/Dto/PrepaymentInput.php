<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Dto;

use App\Domain\Money\Money;

/**
 * Vorauszahlungen eines Mietverhältnisses (Pflichtenheft Abschnitt 11.4).
 *
 * Abgezogen werden ausschließlich die TATSÄCHLICH geleisteten
 * Vorauszahlungen. Der Sollwert dient nur der Plausibilisierung.
 *
 * Liegen keine Ist-Daten vor, darf Ist = Soll nur mit ausdrücklicher
 * Bestätigung übernommen werden (assumedFromTarget = true). Das Ergebnis
 * trägt dann das Flag prepaymentAssumedFromTarget, damit die Annahme im PDF
 * und im Prüfbericht sichtbar bleibt.
 */
final readonly class PrepaymentInput
{
    private function __construct(
        public string $occupancyKey,
        public Money $targetAmount,
        public ?Money $actualAmount,
        public bool $assumedFromTarget,
        public ?string $source = null,
    ) {}

    /**
     * Tatsächlich geleistete Vorauszahlungen sind bekannt.
     */
    public static function actual(string $occupancyKey, Money $actualAmount, Money $targetAmount, ?string $source = null): self
    {
        return new self($occupancyKey, $targetAmount, $actualAmount, false, $source);
    }

    /**
     * Ist-Werte liegen nicht vor; der Nutzer hat die Übernahme der Sollwerte
     * ausdrücklich bestätigt.
     */
    public static function assumedFromTarget(string $occupancyKey, Money $targetAmount, ?string $source = null): self
    {
        return new self($occupancyKey, $targetAmount, $targetAmount, true, $source);
    }

    /**
     * Kein Vorauszahlungsbetrag vereinbart oder geleistet.
     */
    public static function none(string $occupancyKey): self
    {
        return new self($occupancyKey, Money::zero(), Money::zero(), false, null);
    }

    /**
     * Abzuziehender Betrag: immer der Ist-Wert.
     */
    public function deductibleAmount(): Money
    {
        return $this->actualAmount ?? Money::zero();
    }

    /**
     * Abweichung Ist gegenüber Soll (positiv = mehr gezahlt als vereinbart).
     */
    public function deviation(): Money
    {
        return $this->deductibleAmount()->minus($this->targetAmount);
    }
}
