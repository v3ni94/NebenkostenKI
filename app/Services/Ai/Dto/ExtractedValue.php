<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Ein validierter Einzelwert mit Quellenbezug.
 *
 * Entspricht dem, was nach Abschnitt 6.4 dauerhaft gespeichert werden darf:
 * Wert, Seite, kurzer Fundstellenausschnitt, Konfidenz und Pruefstatus. Der
 * vollstaendige Text, das Seitenbild und die rohe Modellantwort gehoeren
 * nicht dazu.
 *
 * requiresReview wird vom ConfidenceEvaluator gesetzt, sobald die Konfidenz
 * unter ai.confidence_review_threshold liegt. Die Schicht trifft keine
 * stillen Annahmen, sie kennzeichnet.
 */
final class ExtractedValue
{
    public function __construct(
        public readonly string $path,
        public readonly string|int|float|bool|null $value,
        public readonly float $confidence,
        public readonly ?int $sourcePage = null,
        public readonly ?string $sourceExcerpt = null,
        public readonly ?BoundingBox $boundingBox = null,
        public readonly bool $requiresReview = false,
    ) {}

    public function markRequiresReview(bool $requiresReview = true): self
    {
        return new self(
            $this->path,
            $this->value,
            $this->confidence,
            $this->sourcePage,
            $this->sourceExcerpt,
            $this->boundingBox,
            $requiresReview,
        );
    }

    public function isMissing(): bool
    {
        return $this->value === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'value' => $this->value,
            'confidence' => $this->confidence,
            'source_page' => $this->sourcePage,
            'source_excerpt' => $this->sourceExcerpt,
            'bounding_box' => $this->boundingBox?->toArray(),
            'requires_review' => $this->requiresReview,
        ];
    }
}
