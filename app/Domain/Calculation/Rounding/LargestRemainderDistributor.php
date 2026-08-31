<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Rounding;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Deterministische Verteilung eines ganzzahligen Betrags nach dem
 * Largest-Remainder-Verfahren (Hare-Niemeyer).
 *
 * Verbindlicher Rechenweg (Pflichtenheft Abschnitt 11.3):
 * 1. Der exakte Anteil je Beteiligtem wird als Bruch berechnet, ohne jede
 *    Zwischenrundung: exakt_i = Gesamtbetrag × Gewicht_i.
 * 2. Jeder Beteiligte erhält zunächst den abgerundeten Wert (FLOOR).
 * 3. Die verbleibenden ganzen Einheiten werden in der Reihenfolge des
 *    größten Restes verteilt.
 * 4. Bei Gleichstand des Restes entscheidet der Beteiligtenschlüssel
 *    aufsteigend (strcmp). Damit ist das Ergebnis reproduzierbar.
 *
 * Die Summe der Einzelanteile entspricht danach EXAKT dem verteilten
 * Gesamtbetrag, auch bei negativen Gesamtbeträgen (Gutschrift): FLOOR rundet
 * dann nach unten, der positive Rest wird nach demselben Verfahren verteilt.
 *
 * Die Gewichte müssen exakt die Summe 1 ergeben. Ein nicht verteilter
 * Restanteil (z. B. MEA-Anteile außerhalb des Objekts, Leerstand) ist als
 * eigener Beteiligter zu übergeben und wird dadurch nachvollziehbar
 * ausgewiesen, statt die übrigen Anteile stillschweigend zu erhöhen.
 */
final class LargestRemainderDistributor
{
    /**
     * @param  int  $total  zu verteilender Betrag in ganzzahligen Einheiten (Cent)
     * @param  array<string, BigRational>  $weights  Gewichte, Summe exakt 1
     */
    public function distribute(int $total, array $weights): DistributionResult
    {
        if ($weights === []) {
            throw InvalidDistributionException::emptyWeights();
        }

        $sum = BigRational::zero();

        foreach ($weights as $participantKey => $weight) {
            if ($weight->isNegative()) {
                throw InvalidDistributionException::negativeWeight((string) $participantKey, (string) $weight);
            }

            $sum = $sum->plus($weight);
        }

        if (! $sum->isEqualTo(BigRational::one())) {
            throw InvalidDistributionException::weightsNotNormalized((string) $sum->simplified());
        }

        $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($weights));
        sort($keys, SORT_STRING);

        $exact = [];
        $floors = [];
        $remainders = [];
        $assigned = 0;

        foreach ($keys as $key) {
            $exactAmount = $weights[$key]->multipliedBy($total);
            $floor = $exactAmount->toScale(0, RoundingMode::FLOOR)->toInt();

            $exact[$key] = $exactAmount;
            $floors[$key] = $floor;
            $remainders[$key] = $exactAmount->minus($floor);
            $assigned += $floor;
        }

        $openUnits = $total - $assigned;

        $order = $keys;
        usort($order, static function (string $left, string $right) use ($remainders): int {
            $comparison = $remainders[$right]->compareTo($remainders[$left]);

            return $comparison !== 0 ? $comparison : strcmp($left, $right);
        });

        $amounts = $floors;

        for ($i = 0; $i < $openUnits; $i++) {
            $amounts[$order[$i % count($order)]] += 1;
        }

        $adjustments = [];

        foreach ($keys as $key) {
            $commercial = $exact[$key]->toScale(0, RoundingMode::HALF_UP)->toInt();
            $adjustments[$key] = $amounts[$key] - $commercial;
        }

        return new DistributionResult($total, $amounts, $adjustments, $exact);
    }

    /**
     * Verteilt einen Betrag nach beliebigen, nicht normierten Gewichten.
     *
     * Die Gewichte werden zuvor durch ihre Summe geteilt. Nur verwenden, wenn
     * fachlich sicher ist, dass die übergebenen Beteiligten den gesamten
     * Betrag tragen (z. B. Aufteilung eines Verbrauchs innerhalb einer
     * Einheit).
     *
     * @param  array<string, BigRational>  $weights
     */
    public function distributeProportionally(int $total, array $weights): DistributionResult
    {
        if ($weights === []) {
            throw InvalidDistributionException::emptyWeights();
        }

        $sum = BigRational::zero();

        foreach ($weights as $participantKey => $weight) {
            if ($weight->isNegative()) {
                throw InvalidDistributionException::negativeWeight((string) $participantKey, (string) $weight);
            }

            $sum = $sum->plus($weight);
        }

        if ($sum->isZero()) {
            throw InvalidDistributionException::weightsNotNormalized('0');
        }

        $normalized = [];

        foreach ($weights as $participantKey => $weight) {
            $normalized[(string) $participantKey] = $weight->dividedBy($sum);
        }

        return $this->distribute($total, $normalized);
    }
}
