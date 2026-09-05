<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Verweis auf ein dauerhaft gespeichertes Ergebnisartefakt.
 *
 * Der SHA-256 ist hier zulaessig und ausdruecklich vorgesehen (Abschnitt 3.6):
 * Er betrifft eine vom System erzeugte Datei, nicht ein Nutzeroriginal, und
 * dient dem Nachweis der Unveraenderlichkeit finaler PDFs.
 */
final class ArtifactReference
{
    public function __construct(
        public readonly ArtifactType $type,
        public readonly string $disk,
        public readonly string $path,
        public readonly int $byteSize,
        public readonly string $sha256,
    ) {}
}
