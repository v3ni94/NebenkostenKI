<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Landlord;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Landlord>
 */
class LandlordFactory extends Factory
{
    protected $model = Landlord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(TestData::FIRST_NAMES).' '.fake()->randomElement(TestData::LAST_NAMES);

        return [
            'organization_id' => Organization::factory(),
            'sender_name' => $name,
            'company_name' => null,
            'address_line' => TestData::street(),
            'postal_code' => TestData::postalCode(),
            'city' => TestData::city(),
            'country' => 'DE',
            'email' => 'vermieter.'.fake()->unique()->numberBetween(1000, 999999).'@beispiel.invalid',
            'iban' => TestData::PLACEHOLDER_IBAN,
            'bic' => TestData::PLACEHOLDER_BIC,
            'account_holder' => $name,
            'show_bank_details_on_statement' => true,
        ];
    }
}
