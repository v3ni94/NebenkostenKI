<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Grenzwerte des Uploads aus config('smartabrechnen.uploads').
 *
 * Standard sind 25 MB je Datei und 250 MB je Abrechnungslauf, beides per ENV
 * konfigurierbar (Abschnitt 6.1).
 */
final class UploadLimits
{
    private function __construct(
        public readonly int $maxFileBytes,
        public readonly int $maxRunBytes,
        public readonly int $chunkBytes,
    ) {}

    public static function fromConfig(): self
    {
        $uploads = config('smartabrechnen.uploads');
        $uploads = is_array($uploads) ? $uploads : [];

        $maxFileMb = self::intFrom($uploads, 'max_file_mb', 25);
        $maxRunMb = self::intFrom($uploads, 'max_run_mb', 250);
        $chunkMb = self::intFrom($uploads, 'chunk_size_mb', 4);

        return new self(
            max(1, $maxFileMb) * 1024 * 1024,
            max(1, $maxRunMb) * 1024 * 1024,
            max(1, $chunkMb) * 1024 * 1024,
        );
    }

    public static function of(int $maxFileBytes, int $maxRunBytes, int $chunkBytes): self
    {
        return new self($maxFileBytes, $maxRunBytes, $chunkBytes);
    }

    /**
     * Erwartete Anzahl Dateiabschnitte fuer eine angekuendigte Dateigroesse.
     */
    public function expectedChunkCount(int $byteSize): int
    {
        return max(1, (int) ceil($byteSize / $this->chunkBytes));
    }

    public function maxFileMegabytes(): int
    {
        return intdiv($this->maxFileBytes, 1024 * 1024);
    }

    public function maxRunMegabytes(): int
    {
        return intdiv($this->maxRunBytes, 1024 * 1024);
    }

    /**
     * @return list<string>
     */
    public static function acceptedMimeTypes(): array
    {
        $uploads = config('smartabrechnen.uploads');
        $accepted = is_array($uploads) ? ($uploads['accepted_mime_types'] ?? []) : [];

        if (! is_array($accepted)) {
            return [];
        }

        return array_values(array_filter($accepted, static fn (mixed $value): bool => is_string($value)));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function intFrom(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }
}
