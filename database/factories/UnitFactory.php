<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Property;
use App\Models\Unit;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, Property::class, 'property_id'),
            'label' => 'WE '.fake()->unique()->numberBetween(1, 400),
            'location' => fake()->randomElement(['EG links', 'EG rechts', '1. OG links', '1. OG rechts', '2. OG mitte', 'DG']),
            'unit_number' => (string) fake()->numberBetween(1, 40),
            'living_area_sqm' => '72.5000',
            'heated_area_sqm' => '70.2500',
            'mea' => '87.500000',
            'room_count' => 3,
            'individual_key_1_value' => '1.0000',
            'is_commercial' => false,
            'is_owner_occupied' => false,
        ];
    }

    public function commercial(): static
    {
        return $this->state(fn (array $attributes): array => ['is_commercial' => true]);
    }
}
