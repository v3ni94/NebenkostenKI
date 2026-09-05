<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Dto;

/**
 * Eine Zeile der Reconciliation-Matrix aus Abschnitt 7.4.
 *
 * Spalten: Quelle, Betrag, Einheit, Zeitraum, vorgeschlagene Behandlung.
 * Die Matrix ist eine Darstellung. Sie rechnet nicht und setzt nichts an.
 */
final readonly class HeatingMatrixRow
{
    public function __construct(
        public HeatingSourceKind $sourceKind,
        public string $sourceLabel,
        public ?int $amountCent,
        public string $unitLabel,
        public string $periodLabel,
        public string $treatment,
        public bool $applied,
        public ?string $documentId = null,
    ) {}
}
