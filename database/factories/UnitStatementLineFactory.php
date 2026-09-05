<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AllocationKeyType;
use App\Enums\Paragraph35aType;
use App\Models\UnitStatement;
use App\Models\UnitStatementLine;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitStatementLine>
 */
class UnitStatementLineFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = UnitStatementLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_statement_id' => UnitStatement::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, UnitStatement::class, 'unit_statement_id'),
            'category_label' => 'Gebaeudereinigung',
            'betrkv_reference' => 'BetrKV Paragraf 2 Nummer 9',
            'total_cost_cent' => 128450,
            'allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
            'allocation_key_label' => 'Wohnflaeche in Quadratmetern',
            'numerator' => '72.500000',
            'denominator' => '480.000000',
            'time_factor' => '1.00000000',
            'share_cent' => 19401,
            'rounding_adjustment_cent' => 0,
            'is_heating_line' => false,
            'paragraph_35a_type' => Paragraph35aType::NONE,
            'sort_order' => 10,
        ];
    }

    public function heating(): static
    {
        return $this->state(fn (array $attributes): array => [
            'category_label' => 'Heizung',
            'allocation_key_type' => AllocationKeyType::VERBRAUCH,
            'allocation_key_label' => 'Verbrauch in Kilowattstunden',
            'is_heating_line' => true,
        ]);
    }
}
