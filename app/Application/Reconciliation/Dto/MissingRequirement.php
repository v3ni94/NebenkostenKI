<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Dto;

/**
 * Eine fehlende Pflichtangabe.
 *
 * Fehlende Werte werden niemals geschaetzt (Grundsatz 5). Aus jeder fehlenden
 * Pflichtangabe entsteht eine konkrete Pruefaufgabe in validation_issues.
 */
final readonly class MissingRequirement
{
    public function __construct(
        public string $fieldLabel,
        public string $explanation,
        public ?string $documentId = null,
        public bool $blocksFinalization = false,
    ) {}
}
