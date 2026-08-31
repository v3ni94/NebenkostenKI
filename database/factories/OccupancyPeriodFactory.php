<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ValueSource;
use App\Models\OccupancyPeriod;
use App\Models\Tenancy;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OccupancyPeriod>
 */
class OccupancyPeriodFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = OccupancyPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenancy_id' => Tenancy::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, Tenancy::class, 'tenancy_id'),
            'starts_on' => '2025-01-01',
            'ends_on' => '2025-12-31',
            'person_count' => 2,
            'source' => ValueSource::MANUELL,
        ];
    }
}
