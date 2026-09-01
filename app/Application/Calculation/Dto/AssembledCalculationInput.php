<?php

declare(strict_types=1);

namespace App\Application\Calculation\Dto;

use App\Domain\Calculation\Dto\StatementCalculationInput;

/**
 * Ergebnis des Eingabeaufbaus.
 *
 * Neben der reinen Domaineingabe fuehrt das Objekt die Rueckabbildung auf die
 * Datenbankschluessel. Nur damit lassen sich die Ergebnisse anschliessend in
 * unit_statements und unit_statement_lines schreiben, ohne dass die
 * Domainschicht Eloquent kennen muss.
 */
final readonly class AssembledCalculationInput
{
    /**
     * @param  array<string, string>  $tenancyIdByOccupancyKey
     * @param  array<string, string>  $unitIdByUnitKey
     * @param  array<string, string|null>  $costCategoryIdByCostItemKey
     * @param  list<string>  $heatingCategoryKeys
     * @param  array<string, string>  $allocationKeyTypeByRef  Schlüsselreferenz => App\Enums\AllocationKeyType
     */
    public function __construct(
        public StatementCalculationInput $input,
        public array $tenancyIdByOccupancyKey,
        public array $unitIdByUnitKey,
        public array $costCategoryIdByCostItemKey,
        public array $heatingCategoryKeys = [],
        public array $allocationKeyTypeByRef = [],
    ) {}

    public function tenancyId(string $occupancyKey): ?string
    {
        return $this->tenancyIdByOccupancyKey[$occupancyKey] ?? null;
    }

    public function unitId(string $unitKey): ?string
    {
        return $this->unitIdByUnitKey[$unitKey] ?? null;
    }

    public function costCategoryId(string $costItemKey): ?string
    {
        return $this->costCategoryIdByCostItemKey[$costItemKey] ?? null;
    }

    public function isHeatingCategory(string $categoryKey): bool
    {
        return in_array($categoryKey, $this->heatingCategoryKeys, true);
    }
}
