<?php

declare(strict_types=1);

namespace App\Services\Storage;

use RuntimeException;

/**
 * Klartextdatei auf der Platte als Quelle.
 *
 * Nur fuer Tests, lokale Werkzeuge und die Pfadvarianten der Pruefer. Im
 * Kurzzeitbereich der Anwendung existiert keine Klartextdatei; dort wird
 * EncryptedFileSource verwendet.
 */
final class PlainFileSource implements ReadableSource
{
    public function __construct(private readonly string $absolutePath) {}

    public function exists(): bool
    {
        return is_file($this->absolutePath);
    }

    public function byteSize(): int
    {
        return $this->exists() ? (int) filesize($this->absolutePath) : 0;
    }

    public function openStream()
    {
        $handle = fopen($this->absolutePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Die Quelldatei konnte nicht geoeffnet werden.');
        }

        return $handle;
    }

    public function withLocalPath(callable $callback): mixed
    {
        return $callback($this->absolutePath);
    }
}
