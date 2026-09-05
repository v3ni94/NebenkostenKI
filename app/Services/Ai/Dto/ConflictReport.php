<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Bericht ueber alle Widersprueche eines Dual-Review-Laufs.
 *
 * Es gibt bewusst keine Methode, die einen Gewinner ermittelt. Ein
 * Mehrheitsentscheid oder eine Auswahl nach hoeherer Konfidenz ist nach
 * Abschnitt 13.5 unzulaessig.
 */
final class ConflictReport
{
    /**
     * @param  list<ConflictEntry>  $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly string $providerKeyA,
        public readonly string $providerKeyB,
    ) {}

    public function hasConflicts(): bool
    {
        return $this->entries !== [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(
            static fn (ConflictEntry $entry): string => $entry->path,
            $this->entries,
        );
    }
}
