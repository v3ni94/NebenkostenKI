<?php

declare(strict_types=1);

namespace App\Application\Wizard\Dto;

/**
 * Ein Zähler eines Verteilerschlüssels.
 *
 * Bezugsebene ist entweder eine Einheit oder ein Mietverhältnis. Fehlt der
 * Wert, wird das rot UND zusätzlich als Text ausgewiesen; ein Status wird
 * niemals allein über die Farbe kommuniziert.
 */
final readonly class AllocationValueRow
{
    public function __construct(
        public string $participantId,
        public string $label,
        public ?string $value,
        public bool $isUnitScope,
        public ?string $herkunft = null,
    ) {}

    public function isMissing(): bool
    {
        return $this->value === null || trim($this->value) === '';
    }

    public function missingText(): ?string
    {
        return $this->isMissing()
            ? sprintf('Für %s fehlt der Wert. Bitte ergänzen Sie ihn.', $this->label)
            : null;
    }
}
