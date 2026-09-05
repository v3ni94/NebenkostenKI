<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Dto;

/**
 * Getrennt ausgewiesene, verbindlich ausgeschlossene Position der
 * WEG-Abrechnung (Abschnitt 7.2).
 *
 * Die Zeile wird niemals in die Mieterumlage uebernommen. Sie bleibt sichtbar,
 * damit der Nutzer die Abrechnung der WEG nachvollziehen kann.
 */
final readonly class ExcludedPositionRow
{
    public function __construct(
        public string $positionKey,
        public string $label,
        public int $amountCent,
        public string $kind,
        public string $kindLabel,
        public string $reason,
        public bool $declaredAllocableByManager = false,
    ) {}
}
