<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AllocationKeyType;
use App\Enums\ApportionmentStatus;
use App\Enums\Paragraph35aType;
use App\Models\CostCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostCategory>
 */
class CostCategoryFactory extends Factory
{
    protected $model = CostCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'code' => 'TEST_'.fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Testkategorie',
            'betrkv_reference' => 'BetrKV Paragraf 2',
            'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
            'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
            'paragraph_35a_type' => Paragraph35aType::NONE,
            'excluded_from_apportionment_by_default' => false,
            'requires_contract_basis' => false,
            'requires_manual_review' => false,
            'is_heating_related' => false,
            'is_warm_water_related' => false,
            'supports_labor_share' => false,
            'is_custom' => false,
            'sort_order' => 100,
            'valid_from' => '2024-01-01',
            'valid_to' => null,
        ];
    }

    public function notApportionable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'apportionment_status' => ApportionmentStatus::NICHT_UMLAGEFAEHIG,
            'excluded_from_apportionment_by_default' => true,
        ]);
    }
}
