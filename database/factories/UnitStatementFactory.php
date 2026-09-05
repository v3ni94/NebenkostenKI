<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatementResultKind;
use App\Enums\UnitStatementStatus;
use App\Models\BillingRun;
use App\Models\Tenancy;
use App\Models\UnitStatement;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitStatement>
 */
class UnitStatementFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = UnitStatement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'tenancy_id' => Tenancy::factory(),
            'unit_id' => fn (array $attributes): string => $this->tenancyColumn($attributes, 'unit_id'),
            'sequence_number' => fake()->unique()->numberBetween(1, 500),
            'version_number' => 1,
            'usage_period_start' => '2025-01-01',
            'usage_period_end' => '2025-12-31',
            'days_used' => 365,
            'period_days' => 365,
            'total_apportionable_cent' => 214560,
            'total_heating_cent' => 156800,
            'total_excluded_cent' => 48200,
            'prepayment_target_cent' => 288000,
            'prepayment_actual_cent' => 288000,
            'balance_cent' => -73440,
            'rounding_adjustment_total_cent' => 1,
            'result_kind' => StatementResultKind::GUTHABEN,
            'status' => UnitStatementStatus::BERECHNET,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tenancyColumn(array $attributes, string $column): string
    {
        $tenancyId = $attributes['tenancy_id'] ?? null;

        if ($tenancyId === null) {
            return '';
        }

        $value = Tenancy::query()->whereKey($tenancyId)->value($column);

        return is_string($value) ? $value : '';
    }

    public function finalized(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UnitStatementStatus::FINAL,
        ]);
    }

    public function additionalPayment(): static
    {
        return $this->state(fn (array $attributes): array => [
            'prepayment_target_cent' => 180000,
            'prepayment_actual_cent' => 180000,
            'balance_cent' => 34560,
            'result_kind' => StatementResultKind::NACHZAHLUNG,
        ]);
    }
}
