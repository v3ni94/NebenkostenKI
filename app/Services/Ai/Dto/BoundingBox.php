<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Optionale Koordinaten einer Fundstelle, relativ zur Seite.
 *
 * Die Bounding Box ist ein Positionsverweis, kein Seitenbild. Sie darf
 * gespeichert werden, ein Seitenbild oder eine Seitenvorschau nicht
 * (Grundsatz 4, Abschnitt 6.4).
 */
final class BoundingBox
{
    public function __construct(
        public readonly int $page,
        public readonly float $x,
        public readonly float $y,
        public readonly float $width,
        public readonly float $height,
    ) {}

    /**
     * @return array<string, float|int>
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
