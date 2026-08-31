<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Dto;

/**
 * Validierte Stammdaten einer Einheit.
 *
 * Reines Domain-DTO ohne Eloquent-Bezug. Die Anwendungsschicht übergibt
 * ausschließlich geprüfte Werte; Flächen und Anteile als Dezimalstrings,
 * damit keine binären Floats entstehen.
 */
final readonly class UnitInput
{
    /**
     * @param  string  $unitKey  eindeutiger, stabiler Schlüssel der Einheit (Sortierkriterium bei Rundungsgleichstand)
     * @param  array<int, string>  $individualValues  individuelle Schlüsselwerte 1 bis 5
     */
    public function __construct(
        public string $unitKey,
        public string $label,
        public ?string $livingAreaSqm = null,
        public ?string $heatedAreaSqm = null,
        public ?string $coOwnershipShare = null,
        public array $individualValues = [],
    ) {}

    public function individualValue(int $index): ?string
    {
        return $this->individualValues[$index] ?? null;
    }
}
