<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Ergebnis der serverseitigen Wiederzusammensetzung eines Chunk-Uploads.
 */
final class ChunkAssemblyResult
{
    public function __construct(
        public readonly string $storageKey,
        public readonly int $byteSize,
        public readonly bool $alreadyAssembled,
    ) {}
}
