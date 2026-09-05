<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MeterType;
use App\Models\MeterDevice;
use App\Models\Property;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeterDevice>
 */
class MeterDeviceFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = MeterDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, Property::class, 'property_id'),
            'unit_id' => null,
            'meter_type' => MeterType::KALTWASSER,
            'meter_number' => 'ZN-'.fake()->unique()->numberBetween(100000, 999999),
            'measurement_unit' => 'm3',
            'location' => 'Kellerverteilung',
            'installed_on' => '2022-03-15',
        ];
    }

    public function heatMeter(): static
    {
        return $this->state(fn (array $attributes): array => [
            'meter_type' => MeterType::WAERMEMENGE,
            'measurement_unit' => 'kWh',
        ]);
    }
}
