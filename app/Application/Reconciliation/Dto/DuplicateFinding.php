<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Dto;

/**
 * Moegliche Dublette auf Positionsebene.
 *
 * Ein Treffer wird niemals still addiert und niemals still entfernt. Er wird
 * als Beziehung und als Pruefaufgabe gefuehrt; der Nutzer entscheidet.
 */
final readonly class DuplicateFinding
{
    public function __construct(
        public string $costItemId,
        public string $duplicateOfCostItemId,
        public string $reason,
        public bool $isCreditNotePair = false,
        public ?string $documentId = null,
        public ?string $duplicateOfDocumentId = null,
    ) {}
}
