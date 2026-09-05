<?php

declare(strict_types=1);

namespace App\Application\Install;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Lebenszeichen des Schedulers.
 *
 * Auf IONOS Profil A gibt es keinen dauerhaften Worker. Ob der Cronjob des
 * Control-Centers schedule:run tatsaechlich aufruft, laesst sich nur an einem
 * Zeitstempel erkennen, den der Scheduler selbst setzt. routes/console.php
 * schreibt ihn bei jedem Lauf; smartabrechnen:check-config liest ihn.
 *
 * Der Zeitstempel liegt im konfigurierten Cache. Produktiv ist das die
 * Datenbank (CACHE_STORE=database), damit Web- und CLI-Prozess denselben
 * Wert sehen.
 */
final class SchedulerHeartbeat
{
    public const string CACHE_KEY = 'smartabrechnen.scheduler.last_run';

    /**
     * Nach dieser Zeit ohne Lauf gilt der Cronjob als nicht eingerichtet.
     */
    public const int STALE_AFTER_MINUTES = 15;

    public function __construct(private readonly Repository $cache) {}

    public function record(?Carbon $now = null): void
    {
        $now ??= Carbon::now();

        try {
            $this->cache->forever(self::CACHE_KEY, $now->toIso8601String());
        } catch (Throwable) {
            // Ein fehlender Cache darf den Scheduler nicht anhalten. Der
            // Konfigurationscheck meldet den fehlenden Zeitstempel.
        }
    }

    public function lastRun(): ?Carbon
    {
        try {
            $value = $this->cache->get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    public function isStale(?Carbon $now = null): bool
    {
        $last = $this->lastRun();

        if ($last === null) {
            return true;
        }

        return $last->diffInMinutes($now ?? Carbon::now(), false) > self::STALE_AFTER_MINUTES;
    }
}
