<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = TestData::city();

        return [
            'name' => 'Hausbesitz '.fake()->randomElement(TestData::LAST_NAMES),
            'type' => OrganizationType::PRIVATPERSON,
            'billing_name' => null,
            'billing_address_line' => TestData::street(),
            'billing_postal_code' => TestData::postalCode(),
            'billing_city' => $city,
            'billing_country' => 'DE',
            'vat_id' => null,
            'contact_email' => 'kontakt.'.fake()->unique()->numberBetween(1000, 999999).'@beispiel.invalid',
        ];
    }

    public function propertyManagement(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => OrganizationType::HAUSVERWALTUNG,
            'legal_form' => 'GmbH',
        ]);
    }
}
