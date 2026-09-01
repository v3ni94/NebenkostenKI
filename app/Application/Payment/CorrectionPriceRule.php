<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Payment\Dto\CorrectionCharge;
use App\Domain\Money\Money;
use App\Models\BillingRun;
use Illuminate\Support\Carbon;

/**
 * Preisregel fuer Korrekturen nach der Zahlung (Abschnitt 11.5).
 *
 * VERBINDLICHE REGELN
 *
 *  1. Ein finalisiertes PDF wird niemals ueberschrieben. Eine Korrektur erzeugt
 *     eine neue Version, die alte behaelt den Status ERSETZT. Das setzt
 *     FinalizeBillingRun um; diese Klasse entscheidet ausschliesslich ueber den
 *     Preis.
 *  2. Ob eine Korrektur kostenfrei ist, steuert eine administrativ festgelegte
 *     Frist in Tagen ab der Finalisierung. Der Wert wird aus
 *     config('smartabrechnen.pricing.correction_free_days') gelesen.
 *  3. Ist der Wert nicht gesetzt, gilt die konservative Annahme 0 Tage: eine
 *     Korrektur ist dann nicht automatisch kostenfrei. Es wird bewusst keine
 *     Frist erfunden. Der Betreiber legt den Wert vor Livegang fest; der Punkt
 *     ist als offener Punkt dokumentiert.
 *  4. Kein erneuter Betrag ohne transparente Anzeige und ausdrueckliche
 *     Bestaetigung. Diese Klasse gibt den Betrag deshalb ausschliesslich als
 *     Angebot mit Hinweistext zurueck und loest selbst keine Zahlung aus.
 */
final class CorrectionPriceRule
{
    public const string CONFIG_KEY = 'smartabrechnen.pricing.correction_free_days';

    public function __construct(private readonly CalculatePrice $prices) {}

    public function freeDays(): int
    {
        $value = config(self::CONFIG_KEY);

        return is_int($value) && $value >= 0 ? $value : 0;
    }

    /**
     * Preisregel auf einen konkreten Lauf anwenden.
     */
    public function evaluate(BillingRun $billingRun, ?Carbon $now = null): CorrectionCharge
    {
        $now ??= Carbon::now();
        $freeDays = $this->freeDays();
        $finalizedAt = $billingRun->getAttribute('finalized_at');

        if ($freeDays > 0
            && $finalizedAt instanceof Carbon
            && $finalizedAt->copy()->addDays($freeDays)->greaterThanOrEqualTo($now)) {
            return new CorrectionCharge(
                true,
                null,
                sprintf(
                    'Diese Korrektur ist kostenfrei. Die kostenfreie Frist von %d Tagen nach der Finalisierung '
                    .'am %s ist noch nicht abgelaufen.',
                    $freeDays,
                    $finalizedAt->format('d.m.Y'),
                ),
                $freeDays,
            );
        }

        $count = $this->prices->statementCount($billingRun);
        $quote = $this->prices->estimate($count);

        return new CorrectionCharge(
            false,
            $quote,
            sprintf(
                'Für diese Korrektur fällt ein Betrag von %s brutto für %d %s an. '
                .'Der Betrag wird erst nach Ihrer ausdrücklichen Bestätigung erhoben. %s',
                Money::fromCents($quote->grossCent)->format(),
                $count,
                $count === 1 ? 'Mieterabrechnung' : 'Mieterabrechnungen',
                $freeDays > 0
                    ? sprintf('Die kostenfreie Frist von %d Tagen ist abgelaufen.', $freeDays)
                    : 'Eine kostenfreie Frist ist nicht konfiguriert.',
            ),
            $freeDays,
        );
    }
}
