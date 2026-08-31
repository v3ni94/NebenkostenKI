<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'position' => 1,
            'description' => 'Erstellung Betriebskostenabrechnung, Objekt Lindenweg 4, Zeitraum 2025',
            'quantity' => '3.0000',
            'unit_price_net_cent' => 2092,
            'net_cent' => 6277,
            'tax_rate_percent' => '19.0000',
            'tax_cent' => 1193,
            'gross_cent' => 7470,
        ];
    }
}
