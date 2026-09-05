<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Co2ShareStatus;
use App\Enums\HeatingSupplyCase;
use App\Models\BillingRun;
use App\Models\HeatingStatement;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeatingStatement>
 */
class HeatingStatementFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = HeatingStatement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'provider_name' => fake()->randomElement(TestData::HEATING_PROVIDERS),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'supply_case' => HeatingSupplyCase::EXTERN_ABGERECHNET,
            'total_cost_cent' => 942000,
            'basic_cost_cent' => 282600,
            'consumption_cost_cent' => 659400,
            'heating_cost_cent' => 754000,
            'warm_water_cost_cent' => 188000,
            'operating_current_cent' => 24000,
            'co2_cost_cent' => 31000,
            'basic_cost_share_percent' => '30.0000',
            'co2_share_status' => Co2ShareStatus::ENTHALTEN,
            'checksum_ok' => true,
            'checksum_difference_cent' => 0,
        ];
    }

    public function co2Unknown(): static
    {
        return $this->state(fn (array $attributes): array => [
            'co2_share_status' => Co2ShareStatus::UNBEKANNT,
            'co2_cost_cent' => null,
        ]);
    }
}
