<?php

declare(strict_types=1);

namespace App\Application\Admin;

/**
 * Ergebnis der Livegang-Pruefung.
 *
 * Der Bericht ist nur eine Sicht auf den festgestellten Zustand. Er aendert
 * nichts und speichert nichts.
 */
final readonly class LaunchBlockerReport
{
    /**
     * @param  list<LaunchBlocker>  $blockers
     */
    public function __construct(public array $blockers) {}

    public function count(): int
    {
        return count($this->blockers);
    }

    public function isClear(): bool
    {
        return $this->blockers === [];
    }

    public function has(string $key): bool
    {
        foreach ($this->blockers as $blocker) {
            if ($blocker->key === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (LaunchBlocker $blocker): string => $blocker->key, $this->blockers);
    }

    /**
     * Blocker nach Bereich gruppiert, fuer die Anzeige.
     *
     * @return array<string, list<LaunchBlocker>>
     */
    public function byArea(): array
    {
        $grouped = [];

        foreach ($this->blockers as $blocker) {
            $grouped[$blocker->area][] = $blocker;
        }

        return $grouped;
    }

    public function blockingCount(): int
    {
        return count(array_filter(
            $this->blockers,
            static fn (LaunchBlocker $blocker): bool => $blocker->isBlocking(),
        ));
    }
}
