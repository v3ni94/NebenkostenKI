<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PropertyKind;
use App\Models\Landlord;
use App\Models\Organization;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $street = TestData::street();

        return [
            'organization_id' => Organization::factory(),
            'landlord_id' => null,
            'label' => 'Objekt '.$street,
            'address_line' => $street,
            'postal_code' => TestData::postalCode(),
            'city' => TestData::city(),
            'country' => 'DE',
            'kind' => PropertyKind::MEHRFAMILIENHAUS,
            'total_living_area_sqm' => '480.0000',
            'total_heated_area_sqm' => '460.5000',
            'mea_denominator' => '1000.000000',
            'individual_key_1_label' => 'Stellplaetze',
            'is_active' => true,
        ];
    }

    public function condominium(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => PropertyKind::EIGENTUMSWOHNUNG,
            'total_living_area_sqm' => '72.5000',
            'total_heated_area_sqm' => '72.5000',
        ]);
    }

    public function withLandlord(): static
    {
        return $this->state(fn (array $attributes): array => [
            'landlord_id' => Landlord::factory()->state([
                'organization_id' => $attributes['organization_id'] ?? null,
            ]),
        ]);
    }
}
