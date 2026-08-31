<?php

declare(strict_types=1);

namespace App\Application\Documents\Dto;

/**
 * Zustand eines Chunk-Uploads nach Annahme eines Abschnitts.
 *
 * Die fehlenden Abschnitte werden mitgegeben, damit der Browser nach einem
 * Abbruch genau dort fortsetzt und nicht die gesamte Datei erneut uebertraegt.
 */
final class ChunkAcceptanceResult
{
    /**
     * @param  list<int>  $missingChunks
     */
    public function __construct(
        public readonly int $receivedChunks,
        public readonly int $totalChunks,
        public readonly int $receivedBytes,
        public readonly array $missingChunks,
        public readonly bool $duplicate,
    ) {}

    public function isComplete(): bool
    {
        return $this->missingChunks === [];
    }

    /**
     * @return array<string, bool|int|list<int>>
     */
    public function toArray(): array
    {
        return [
            'empfangene_abschnitte' => $this->receivedChunks,
            'abschnitte' => $this->totalChunks,
            'empfangene_bytes' => $this->receivedBytes,
            'fehlende_abschnitte' => $this->missingChunks,
            'bereits_vorhanden' => $this->duplicate,
            'vollstaendig' => $this->isComplete(),
        ];
    }
}
