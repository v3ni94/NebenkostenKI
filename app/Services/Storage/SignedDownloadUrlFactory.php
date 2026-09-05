<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Kurzlebige signierte Links auf Ergebnisartefakte (Abschnitt 3.4 und 19).
 *
 * Die Gueltigkeit kommt aus SIGNED_DOWNLOAD_TTL_MINUTES. Ein signierter Link
 * ersetzt die Autorisierung nicht: Die Zielroute prueft zusaetzlich die
 * Zugehoerigkeit zum Mandanten, damit ein weitergegebener Link nicht wie ein
 * dauerhaftes Zugriffsrecht wirkt.
 */
final class SignedDownloadUrlFactory
{
    private const DEFAULT_TTL_MINUTES = 30;

    public function ttlMinutes(): int
    {
        $value = config('smartabrechnen.retention.signed_download_ttl_minutes');

        if (! is_numeric($value)) {
            return self::DEFAULT_TTL_MINUTES;
        }

        return max(1, (int) $value);
    }

    public function expiresAt(): Carbon
    {
        return Carbon::now()->addMinutes($this->ttlMinutes());
    }

    /**
     * @param  array<string, string>  $parameters
     */
    public function forRoute(string $routeName, array $parameters): string
    {
        return URL::temporarySignedRoute($routeName, $this->expiresAt(), $parameters);
    }

    /**
     * Bereits abgelaufener Link. Wird ausschliesslich fuer Tests des
     * Ablaufverhaltens benoetigt.
     *
     * @param  array<string, string>  $parameters
     */
    public function expiredForRoute(string $routeName, array $parameters): string
    {
        return URL::temporarySignedRoute(
            $routeName,
            Carbon::now()->subMinute(),
            $parameters
        );
    }
}
