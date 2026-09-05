<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Check;

use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Money\Money;
use App\Domain\Support\GermanNumberFormatter;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Plausibilitätsvergleich gegen das Vorjahr (Pflichtenheft Abschnitt 12.5).
 *
 * Geprüft wird je Kostenart:
 * - Abweichung des Betrags gegenüber dem Vorjahr; Standardwarnung ab 30
 *   Prozent,
 * - im Vorjahr vorhandene, diesmal fehlende Kategorie,
 * - neue Kategorie ohne Vorjahresvergleich.
 *
 * Die Abweichung wird exakt als Bruch berechnet und nur für die Anzeige
 * gerundet. Der Vergleich ist ein Hinweis, keine Rechtsbewertung.
 */
final class PreviousYearComparator
{
    /**
     * Standardschwelle der Abweichungswarnung in Prozent.
     */
    public const int DEFAULT_THRESHOLD_PERCENT = 30;

    /**
     * @param  list<CostItemInput>  $costItems
     * @param  list<PreviousYearCategoryAmount>  $previousYear
     * @return list<CheckFinding>
     */
    public function compare(array $costItems, array $previousYear, int $thresholdPercent = self::DEFAULT_THRESHOLD_PERCENT): array
    {
        $currentByCategory = [];
        $labels = [];

        foreach ($costItems as $item) {
            $currentByCategory[$item->categoryKey] = ($currentByCategory[$item->categoryKey] ?? Money::zero())
                ->plus($item->totalAmount);
            $labels[$item->categoryKey] = $item->categoryLabel;
        }

        $findings = [];
        $previousKeys = [];

        foreach ($previousYear as $previous) {
            $previousKeys[] = $previous->categoryKey;
            $current = $currentByCategory[$previous->categoryKey] ?? null;

            if (! $current instanceof Money) {
                $findings[] = CheckFinding::warning(
                    CheckCode::PREVIOUS_YEAR_CATEGORY_MISSING,
                    sprintf(
                        'Die Kostenart "%s" war im Vorjahr mit %s enthalten und fehlt in diesem Abrechnungslauf.',
                        $previous->categoryLabel,
                        $previous->amount->format()
                    ),
                    ['categoryKey' => $previous->categoryKey]
                );

                continue;
            }

            if ($previous->amount->isZero()) {
                continue;
            }

            $deviation = $current->minus($previous->amount);
            $percent = BigRational::nd($deviation->cents, $previous->amount->cents)->multipliedBy(100);
            $absolutePercent = $percent->abs()->toScale(1, RoundingMode::HALF_UP);

            if ($absolutePercent->isGreaterThanOrEqualTo($thresholdPercent)) {
                $findings[] = CheckFinding::warning(
                    CheckCode::PREVIOUS_YEAR_DEVIATION,
                    sprintf(
                        'Die Kostenart "%s" weicht mit %s um %s Prozent (%s) vom Vorjahresbetrag %s ab. '
                        .'Die Abweichung ist zu prüfen.',
                        $labels[$previous->categoryKey] ?? $previous->categoryLabel,
                        $current->format(),
                        GermanNumberFormatter::decimal($percent->toScale(1, RoundingMode::HALF_UP), 1),
                        $deviation->format(),
                        $previous->amount->format()
                    ),
                    [
                        'categoryKey' => $previous->categoryKey,
                        'deviationCent' => $deviation->cents,
                        'thresholdPercent' => $thresholdPercent,
                    ]
                );
            }
        }

        foreach ($currentByCategory as $categoryKey => $amount) {
            if (in_array((string) $categoryKey, $previousKeys, true)) {
                continue;
            }

            $findings[] = CheckFinding::info(
                CheckCode::PREVIOUS_YEAR_CATEGORY_NEW,
                sprintf(
                    'Die Kostenart "%s" (%s) hat keinen Vorjahresvergleich.',
                    $labels[$categoryKey] ?? (string) $categoryKey,
                    $amount->format()
                ),
                ['categoryKey' => (string) $categoryKey]
            );
        }

        return $findings;
    }
}
