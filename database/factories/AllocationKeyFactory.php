<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Models\AllocationKey;
use App\Models\BillingRun;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AllocationKey>
 */
class AllocationKeyFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = AllocationKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'key_type' => AllocationKeyType::WOHNFLAECHE,
            'source' => AllocationKeySource::MIETVERTRAG,
            'denominator' => '480.000000',
            'measurement_unit' => 'm2',
            'label' => 'Wohnflaeche in Quadratmetern',
            'confirmed_at' => now(),
        ];
    }

    public function fromDefault(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => AllocationKeySource::DEFAULT,
            'confirmed_at' => null,
        ]);
    }
}
