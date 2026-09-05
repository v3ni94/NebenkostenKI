<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Ergebnis der Einzelpruefung eines Archiveintrags.
 *
 * DATENSCHUTZ: Der Eintragsname eines Archivs ist ein Originaldateiname und
 * wird deshalb nur fluechtig gefuehrt. Er wird niemals gespeichert und niemals
 * protokolliert. Dauerhaft gilt allein die neutrale Quellenbezeichnung des
 * daraus erzeugten Dokuments.
 */
final class ArchiveEntryReport
{
    public function __construct(
        public readonly int $index,
        public readonly string $extension,
        public readonly int $compressedSize,
        public readonly int $uncompressedSize,
    ) {}
}
