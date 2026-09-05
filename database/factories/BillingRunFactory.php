<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingMode;
use App\Enums\BillingRunStatus;
use App\Models\BillingRun;
use App\Models\Property;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingRun>
 */
class BillingRunFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = BillingRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, Property::class, 'property_id'),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'billing_year' => 2025,
            'mode' => BillingMode::QUICK_CONDO,
            'status' => BillingRunStatus::DRAFT,
            'wizard_step' => 1,
            'statement_count' => 0,
            'uploaded_bytes' => 0,
        ];
    }

    public function status(BillingRunStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    public function fullProperty(): static
    {
        return $this->state(fn (array $attributes): array => ['mode' => BillingMode::FULL_PROPERTY]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BillingRunStatus::PAID,
            'paid_at' => now(),
            'statement_count' => 3,
            'price_per_statement_gross_cent' => 2490,
            'price_base_gross_cent' => 0,
            'price_total_gross_cent' => 7470,
            'vat_rate_percent' => '19.0000',
            'price_locked_at' => now(),
        ]);
    }
}
