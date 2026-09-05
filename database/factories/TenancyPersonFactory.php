<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenancy;
use App\Models\TenancyPerson;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenancyPerson>
 */
class TenancyPersonFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = TenancyPerson::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenancy_id' => Tenancy::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, Tenancy::class, 'tenancy_id'),
            'salutation' => fake()->randomElement(['Frau', 'Herr']),
            'first_name' => fake()->randomElement(TestData::FIRST_NAMES),
            'last_name' => fake()->randomElement(TestData::LAST_NAMES),
            'email' => 'mieter.'.fake()->unique()->numberBetween(1000, 999999).'@beispiel.invalid',
            'is_primary_contact' => true,
        ];
    }
}
