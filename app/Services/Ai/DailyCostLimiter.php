<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\DailyCostLimitExceededException;

/**
 * Prueft ein uebergebenes Tagesbudget gegen
 * ai.max_daily_cost_cent_per_user (Abschnitt 13.8).
 *
 * VERBINDLICH: Kein Datenbankzugriff. Der bereits verbrauchte Tagesbetrag
 * wird als Parameter uebergeben. Die Ermittlung des Verbrauchs ist Aufgabe
 * der Application-Schicht, die dafuer ai_calls auswertet. Damit bleibt die
 * KI-Schicht ohne Persistenzabhaengigkeit und ohne Framework-Bootstrap
 * testbar.
 *
 * Ist kein Limit konfiguriert, also ai.max_daily_cost_cent_per_user gleich
 * null, gilt kein Tagesbudget. Das ist der Auslieferungszustand und vor
 * Livegang ausdruecklich zu entscheiden.
 *
 * Einheit: Das Limit ist in ganzen Cent konfiguriert, der Verbrauch wird in
 * Tausendstel-Cent gefuehrt. Die Kalkulationsbasis ist in US-Cent angegeben,
 * eine Wechselkursumrechnung findet bewusst nicht statt, siehe CostEstimate.
 */
final class DailyCostLimiter
{
    public function __construct(
        private readonly ?int $maxDailyCostCentPerUser,
    ) {}

    public static function fromConfig(AiConfig $config): self
    {
        return new self($config->maxDailyCostCentPerUser);
    }

    public function isEnabled(): bool
    {
        return $this->maxDailyCostCentPerUser !== null;
    }

    public function limitCent(): ?int
    {
        return $this->maxDailyCostCentPerUser;
    }

    public function limitMilliCent(): ?int
    {
        return $this->maxDailyCostCentPerUser === null
            ? null
            : $this->maxDailyCostCentPerUser * 1000;
    }

    public function remainingMilliCent(int $alreadySpentMilliCent): ?int
    {
        $limit = $this->limitMilliCent();

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - max(0, $alreadySpentMilliCent));
    }

    public function wouldExceed(int $alreadySpentMilliCent, int $plannedMilliCent): bool
    {
        $limit = $this->limitMilliCent();

        if ($limit === null) {
            return false;
        }

        return max(0, $alreadySpentMilliCent) + max(0, $plannedMilliCent) > $limit;
    }

    /**
     * @throws DailyCostLimitExceededException
     */
    public function assertWithinLimit(int $alreadySpentMilliCent, int $plannedMilliCent): void
    {
        if (! $this->wouldExceed($alreadySpentMilliCent, $plannedMilliCent)) {
            return;
        }

        /** @var int $limit */
        $limit = $this->maxDailyCostCentPerUser;

        throw DailyCostLimitExceededException::forBudget(
            $limit,
            max(0, $alreadySpentMilliCent),
            max(0, $plannedMilliCent),
        );
    }
}
