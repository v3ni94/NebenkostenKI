<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Verschluesselte Datei im Kurzzeitbereich als Quelle.
 *
 * Der Klartext wird beim Lesen entschluesselt und als Strom geliefert. Er
 * entsteht nicht auf der Platte, mit der dokumentierten Ausnahme
 * withLocalPath() fuer ZipArchive.
 */
final class EncryptedFileSource implements ReadableSource
{
    public function __construct(
        private readonly TemporaryUploadStorage $storage,
        private readonly string $key,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function exists(): bool
    {
        return $this->storage->exists($this->key);
    }

    public function byteSize(): int
    {
        return $this->storage->size($this->key);
    }

    public function openStream()
    {
        return $this->storage->readStream($this->key);
    }

    public function withLocalPath(callable $callback): mixed
    {
        return $this->storage->withDecryptedCopy($this->key, $callback);
    }
}
