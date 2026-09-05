<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

/**
 * Das Tagesbudget je Nutzer aus ai.max_daily_cost_cent_per_user ist
 * ausgeschoepft.
 *
 * Der Verbrauch wird der Schicht als Parameter uebergeben. Die Schicht
 * greift dafuer nicht auf die Datenbank zu.
 */
final class DailyCostLimitExceededException extends AiException
{
    public static function forBudget(int $limitCent, int $spentMilliCent, int $plannedMilliCent): self
    {
        return new self(sprintf(
            'Tagesbudget von %d Cent ist ausgeschoepft: bereits %s Cent verbraucht, %s Cent geplant.',
            $limitCent,
            self::formatMilliCent($spentMilliCent),
            self::formatMilliCent($plannedMilliCent),
        ));
    }

    private static function formatMilliCent(int $milliCent): string
    {
        return number_format($milliCent / 1000, 3, ',', '.');
    }
}
