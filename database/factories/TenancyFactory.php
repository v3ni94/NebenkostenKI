<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenancyKind;
use App\Enums\TenancyStatus;
use App\Enums\ValueSource;
use App\Models\Tenancy;
use App\Models\Unit;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Tenancy>
 */
class TenancyFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = Tenancy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lastName = fake()->randomElement(TestData::LAST_NAMES);

        return [
            'unit_id' => Unit::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, Unit::class, 'unit_id'),
            'property_id' => fn (array $attributes): string => $this->parentAttribute($attributes, Unit::class, 'unit_id', 'property_id'),
            'kind' => TenancyKind::WOHNRAUM,
            'status' => TenancyStatus::AKTIV,
            'tenant_display_name' => 'Eheleute '.$lastName,
            'delivery_address_line' => TestData::street(),
            'delivery_postal_code' => TestData::postalCode(),
            'delivery_city' => TestData::city(),
            'delivery_country' => 'DE',
            'starts_on' => '2025-01-01',
            'ends_on' => null,
            'monthly_operating_prepayment_cent' => 15000,
            'monthly_heating_prepayment_cent' => 9000,
            'heating_prepayment_separate' => true,
            'operating_costs_apportionment_agreed' => true,
            'other_operating_costs_agreed' => false,
            'contract_data_source' => ValueSource::MIETVERTRAG,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  class-string<Model>  $parentModel
     */
    private function parentAttribute(array $attributes, string $parentModel, string $foreignKey, string $column): string
    {
        $parentId = $attributes[$foreignKey] ?? null;

        if ($parentId === null) {
            return '';
        }

        $value = $parentModel::query()->whereKey($parentId)->value($column);

        return is_string($value) ? $value : '';
    }

    public function movedOut(string $endsOn = '2025-06-30'): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends_on' => $endsOn,
            'status' => TenancyStatus::BEENDET,
        ]);
    }

    public function commercial(): static
    {
        return $this->state(fn (array $attributes): array => ['kind' => TenancyKind::GEWERBE]);
    }
}
