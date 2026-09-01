<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Payment\Dto\PriceQuote;
use App\Application\Payment\Dto\VatDecomposition;
use App\Application\Payment\Exceptions\PriceNotPayableException;
use App\Enums\UnitStatementStatus;
use App\Models\BillingRun;
use App\Models\UnitStatement;

/**
 * Use Case: Endpreis eines Abrechnungslaufs serverseitig berechnen
 * (Abschnitt 1.3, Schritt 11, ADR-010).
 *
 * VERBINDLICHE REGELN
 *
 *  1. Abrechnungseinheit ist eine ERZEUGTE Mieterabrechnung. Gezaehlt werden
 *     die tatsaechlich vorhandenen Mieterabrechnungen des Laufs ohne die
 *     ersetzten Versionen. Bei einem Mieterwechsel entstehen je Einheit
 *     mehrere, und genau diese Anzahl wird berechnet.
 *  2. Die Anzahl wird IMMER aus der Datenbank ermittelt. Es gibt in dieser
 *     Klasse keinen Weg, eine Anzahl oder einen Betrag von aussen
 *     hineinzugeben. Ein manipulierter Formularwert ist damit wirkungslos.
 *  3. Preis, Grundpreis und Steuersatz stammen aus der Konfiguration. Der
 *     zulaessige Korridor der Adminkonfiguration wird geprueft; ein Preis
 *     ausserhalb des Korridors wird als Konfigurationsfehler gemeldet und
 *     nicht stillschweigend abgerundet.
 *  4. Angezeigt wird der Bruttopreis. Netto und Umsatzsteuer werden aus dem
 *     Brutto zurueckgerechnet, siehe VatDecomposition.
 *
 * Der berechnete Stand wird auf dem Lauf vermerkt (price_* Spalten), damit der
 * Webhook spaeter Betrag und Anzahl gegen den Lauf pruefen kann.
 */
final class CalculatePrice
{
    /**
     * Verbindlicher Endpreis unmittelbar vor dem Checkout.
     *
     * @throws PriceNotPayableException
     */
    public function __invoke(BillingRun $billingRun): PriceQuote
    {
        $count = $this->statementCount($billingRun);

        if ($count < 1) {
            throw PriceNotPayableException::withoutStatements();
        }

        return $this->quoteFor($count);
    }

    /**
     * Unverbindliche Schaetzung vor der Vorschau. Sie verwendet dieselbe
     * Rechenweise, damit die spaetere Endabrechnung nicht ueberrascht.
     */
    public function estimate(int $statementCount): PriceQuote
    {
        return $this->quoteFor(max(0, $statementCount));
    }

    /**
     * Anzahl der tatsaechlich erzeugten Mieterabrechnungen des Laufs.
     */
    public function statementCount(BillingRun $billingRun): int
    {
        return UnitStatement::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', '!=', UnitStatementStatus::ERSETZT->value)
            ->count();
    }

    /**
     * Schreibt den berechneten Stand auf den Lauf. Der Betrag wird spaeter im
     * Webhook gegen die Zahlung verglichen.
     */
    public function remember(BillingRun $billingRun, PriceQuote $quote): BillingRun
    {
        $billingRun->forceFill([
            'statement_count' => $quote->statementCount,
            'price_per_statement_gross_cent' => $quote->unitGrossCent,
            'price_base_gross_cent' => $quote->baseGrossCent,
            'price_total_gross_cent' => $quote->grossCent,
            'vat_rate_percent' => $quote->vatRatePercent,
            'price_quoted_at' => now(),
        ])->save();

        return $billingRun;
    }

    /**
     * @throws PriceNotPayableException
     */
    private function quoteFor(int $count): PriceQuote
    {
        $unit = $this->configuredCent('per_statement_gross_cent', 2490);
        $base = max(0, $this->configuredCent('base_gross_cent', 0));
        $rate = $this->vatRatePercent();

        $this->assertWithinAdminRange($unit);

        $gross = $count * $unit + $base;
        $vat = VatDecomposition::fromGross($gross, $rate);

        return new PriceQuote(
            $count,
            $unit,
            $base,
            $gross,
            $vat->netCent,
            $vat->taxCent,
            $rate,
            $this->currency(),
        );
    }

    /**
     * @throws PriceNotPayableException
     */
    private function assertWithinAdminRange(int $unitGrossCent): void
    {
        $range = config('smartabrechnen.pricing.admin_range_gross_cent');

        if (! is_array($range)) {
            return;
        }

        $min = $range['min'] ?? null;
        $max = $range['max'] ?? null;

        if (! is_int($min) || ! is_int($max)) {
            return;
        }

        if ($unitGrossCent < $min || $unitGrossCent > $max) {
            throw PriceNotPayableException::priceOutOfRange($unitGrossCent, $min, $max);
        }
    }

    private function configuredCent(string $key, int $fallback): int
    {
        $value = config('smartabrechnen.pricing.'.$key);

        return is_int($value) ? $value : $fallback;
    }

    private function vatRatePercent(): string
    {
        $value = config('smartabrechnen.pricing.vat_rate_percent');

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : '19';
    }

    private function currency(): string
    {
        $value = config('smartabrechnen.pricing.currency');

        return is_string($value) && $value !== '' ? strtolower($value) : 'eur';
    }
}
