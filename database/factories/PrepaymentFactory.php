<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PrepaymentKind;
use App\Enums\ValueSource;
use App\Models\BillingRun;
use App\Models\Prepayment;
use App\Models\Tenancy;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prepayment>
 */
class PrepaymentFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = Prepayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'tenancy_id' => Tenancy::factory(),
            'kind' => PrepaymentKind::BETRIEBSKOSTEN,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'target_cent' => 180000,
            'actual_cent' => 180000,
            'source' => ValueSource::ZAHLUNGSUEBERSICHT,
            'assumed_equal_to_target' => false,
        ];
    }

    public function assumedFromTarget(): static
    {
        return $this->state(fn (array $attributes): array => [
            'actual_cent' => null,
            'source' => ValueSource::SOLL_ANNAHME,
            'assumed_equal_to_target' => true,
            'confirmed_at' => now(),
        ]);
    }

    public function heating(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => PrepaymentKind::HEIZKOSTEN,
            'target_cent' => 108000,
            'actual_cent' => 108000,
        ]);
    }
}
