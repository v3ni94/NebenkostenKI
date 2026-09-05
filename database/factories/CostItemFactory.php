<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApportionmentStatus;
use App\Enums\CostItemSource;
use App\Enums\CostItemStatus;
use App\Enums\Paragraph35aType;
use App\Models\BillingRun;
use App\Models\CostItem;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostItem>
 */
class CostItemFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = CostItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'cost_category_id' => null,
            'description' => 'Gebaeudereinigung Treppenhaus',
            'supplier_name' => fake()->randomElement(TestData::SUPPLIERS),
            'invoice_number' => 'RE-'.fake()->unique()->numberBetween(10000, 999999),
            'amount_cent' => 128450,
            'net_amount_cent' => 107941,
            'vat_amount_cent' => 20509,
            'vat_rate_percent' => '19.0000',
            'document_date' => '2025-11-14',
            'service_period_start' => '2025-01-01',
            'service_period_end' => '2025-12-31',
            'source' => CostItemSource::KI_EXTRAKTION,
            'status' => CostItemStatus::VORGESCHLAGEN,
            'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
            'excluded_from_apportionment' => false,
            'paragraph_35a_type' => Paragraph35aType::NONE,
            'is_heating_cost' => false,
            'is_warm_water_cost' => false,
            'confidence' => '0.9300',
            'source_page' => 1,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CostItemStatus::BESTAETIGT,
            'confirmed_at' => now(),
        ]);
    }

    public function notApportionable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'description' => 'Verwaltervergütung',
            'apportionment_status' => ApportionmentStatus::NICHT_UMLAGEFAEHIG,
            'excluded_from_apportionment' => true,
        ]);
    }

    public function heating(): static
    {
        return $this->state(fn (array $attributes): array => [
            'description' => 'Heizkosten laut externer Abrechnung',
            'is_heating_cost' => true,
        ]);
    }
}
