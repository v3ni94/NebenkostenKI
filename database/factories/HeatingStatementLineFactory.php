<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HeatingStatement;
use App\Models\HeatingStatementLine;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeatingStatementLine>
 */
class HeatingStatementLineFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = HeatingStatementLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'heating_statement_id' => HeatingStatement::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, HeatingStatement::class, 'heating_statement_id'),
            'unit_label' => 'WE 3',
            'share_total_cent' => 156800,
            'share_basic_cent' => 47040,
            'share_consumption_cent' => 109760,
            'share_heating_cent' => 125440,
            'share_warm_water_cent' => 31360,
            'share_co2_cent' => 5160,
            'consumption' => '4820.0000',
            'consumption_unit' => 'kWh',
            'usage_period_start' => '2025-01-01',
            'usage_period_end' => '2025-12-31',
            'confidence' => '0.9600',
            'source_page' => 2,
        ];
    }
}
