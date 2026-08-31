<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $net = 6277;
        $tax = 1193;

        return [
            'number' => sprintf('NK-2026-%06d', fake()->unique()->numberBetween(1, 999999)),
            'organization_id' => Organization::factory(),
            'customer_name' => 'Hausbesitz '.fake()->randomElement(TestData::LAST_NAMES),
            'customer_address_line' => TestData::street(),
            'customer_postal_code' => TestData::postalCode(),
            'customer_city' => TestData::city(),
            'customer_country' => 'DE',
            'issued_on' => '2026-02-10',
            'service_date' => '2026-02-10',
            'net_cent' => $net,
            'tax_cent' => $tax,
            'gross_cent' => $net + $tax,
            'tax_rate_percent' => '19.0000',
            'currency' => 'eur',
            'status' => InvoiceStatus::FESTGESCHRIEBEN,
            'payment_method' => 'Stripe Checkout',
        ];
    }

    public function cancellation(Invoice $original): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::STORNORECHNUNG,
            'cancels_invoice_id' => $original->getKey(),
            'net_cent' => -1 * (int) $original->getAttribute('net_cent'),
            'tax_cent' => -1 * (int) $original->getAttribute('tax_cent'),
            'gross_cent' => -1 * (int) $original->getAttribute('gross_cent'),
        ]);
    }
}
